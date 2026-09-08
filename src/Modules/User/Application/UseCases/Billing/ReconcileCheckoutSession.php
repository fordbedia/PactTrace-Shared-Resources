<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutReconciliation;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionStatus;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;

/**
 * The safety net behind `/checkout/success`. Stripe redirects the browser
 * back the moment checkout completes, but the `checkout.session.completed` /
 * `customer.subscription.*` webhooks arrive independently — usually within a
 * second, but with no ordering guarantee, and never at all if the webhook
 * endpoint is down/misconfigured. So the landing page asks *this* instead of
 * trusting either the redirect or a race with the webhook.
 *
 * It is not a second implementation of "what does an active subscription
 * mean": when local state hasn't caught up it calls the exact same handlers
 * the webhook calls ({@see LinkStripeCustomerToSubscription},
 * {@see SyncSubscriptionFromStripe}) with event data synthesised from the
 * live Checkout Session. Those two handlers are keyed on `provider_id` /
 * Stripe ids and only `forceFill(...)->save()` — no emails, no
 * once-only side effects — so running them here as well as from the real
 * webhook is idempotent: the second run rewrites the same values. (The
 * notification-bearing billing handlers — RecordPaymentFailure /
 * ClearPaymentFailure / HandleTrialWillEnd — are deliberately *not* reached
 * from here.)
 */
final class ReconcileCheckoutSession
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly BillingProvider $billing,
        private readonly LinkStripeCustomerToSubscription $linkCustomer,
        private readonly SyncSubscriptionFromStripe $syncSubscription,
    ) {
    }

    public function handle(int $providerId, string $sessionId): CheckoutReconciliation
    {
        $subscription = $this->subscriptions->findByProviderId($providerId);

        // 1. The webhook already did its job — the common case. No Stripe call.
        if ($subscription !== null
            && in_array((string) $subscription->status, ['active', 'trialing'], true)
            && (string) ($subscription->stripe_subscription_id ?? '') !== ''
            && $subscription->current_period_ends_at !== null
            && $subscription->current_period_ends_at->isFuture()) {
            return CheckoutReconciliation::confirmed($subscription);
        }

        // 2. Ask Stripe directly.
        $remote = $this->billing->retrieveCheckoutSession($sessionId);

        if (! $remote->found) {
            Log::warning('Checkout reconciliation: Stripe did not resolve the session id.', [
                'provider_id' => $providerId,
            ]);

            return CheckoutReconciliation::failed($subscription);
        }

        // Never reconcile a session that isn't this provider's own.
        if ($remote->clientReferenceId !== null && $remote->clientReferenceId !== (string) $providerId) {
            Log::warning('Checkout reconciliation: session client_reference_id does not match the acting provider.', [
                'provider_id' => $providerId,
                'session_client_reference_id' => $remote->clientReferenceId,
            ]);

            return CheckoutReconciliation::failed($subscription);
        }

        // 3. Still incomplete at Stripe — the webhook has nothing to send yet either.
        if (! $remote->isCompleted()) {
            return CheckoutReconciliation::pending($subscription);
        }

        // 4. Stripe confirms a paid subscription but local state lags — run the
        //    same webhook handlers, from the live session data.
        $this->linkCustomer->handle(new StripeWebhookEventData(
            id: 'reconcile:' . $sessionId,
            type: 'checkout.session.completed',
            object: [
                'client_reference_id' => (string) $providerId,
                'customer' => $remote->customerId ?? ($remote->subscriptionObject['customer'] ?? ''),
                'subscription' => $remote->subscriptionId(),
            ],
        ));

        $this->syncSubscription->handle(new StripeWebhookEventData(
            id: 'reconcile:' . $sessionId,
            type: 'customer.subscription.updated',
            object: $remote->subscriptionObject,
        ));

        $fresh = $this->subscriptions->findByProviderId($providerId);

        if ($fresh === null || (string) ($fresh->stripe_subscription_id ?? '') === '') {
            // The handlers logged why (unknown provider, etc.). Nothing to show.
            return CheckoutReconciliation::pending($fresh);
        }

        return CheckoutReconciliation::confirmed($fresh, $remote);
    }
}
