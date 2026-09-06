<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;

/**
 * `checkout.session.completed` — the only handler that resolves the
 * Provider by `client_reference_id`/metadata rather than a Stripe id,
 * because this is the first event that ever links the two. Only writes
 * `stripe_customer_id`/`stripe_subscription_id`: `plan`/`status` are left to
 * `customer.subscription.created`, which Stripe fires right after and is the
 * authoritative source for those (see .claude/rules/plan.md).
 */
final class LinkStripeCustomerToSubscription
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    public function handle(StripeWebhookEventData $event): void
    {
        $providerId = (string) ($event->object['client_reference_id'] ?? ($event->object['metadata']['provider_id'] ?? ''));
        $customerId = (string) ($event->object['customer'] ?? '');
        $stripeSubscriptionId = (string) ($event->object['subscription'] ?? '');

        if ($providerId === '' || $customerId === '' || $stripeSubscriptionId === '') {
            Log::warning('Stripe webhook ignored: checkout.session.completed missing provider_id/customer/subscription.', [
                'has_provider_id' => $providerId !== '',
                'has_customer' => $customerId !== '',
                'has_subscription' => $stripeSubscriptionId !== '',
            ]);

            return;
        }

        $subscription = $this->subscriptions->findByProviderId((int) $providerId);

        if ($subscription === null) {
            Log::warning('Stripe webhook ignored: checkout.session.completed referenced an unknown provider.', [
                'provider_id' => $providerId,
            ]);

            return;
        }

        $subscription->forceFill([
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $stripeSubscriptionId,
        ]);
        $this->subscriptions->save($subscription);
    }
}
