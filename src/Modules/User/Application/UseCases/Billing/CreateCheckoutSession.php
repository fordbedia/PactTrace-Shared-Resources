<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\StripePriceCatalog;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\BillingInterval;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSession;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionRequest;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * `POST /billing/checkout` — starts a Stripe Checkout Session for the
 * requested plan/interval. Used for a brand-new card-on-file (a tenant still
 * on RegisterProvider's card-less trial) and for a plan switch initiated
 * from a trial with no Stripe subscription yet; once a Stripe subscription
 * exists, plan switches go through ChangeSubscriptionPlan instead — see
 * .claude/rules/plan.md.
 *
 * Reuses the tenant's existing `stripe_customer_id` when one is already on
 * file (a returning trial who already started Checkout once before, or is
 * changing which plan they're subscribing to) so Stripe doesn't mint a
 * second Customer for the same tenant.
 *
 * **No Stripe-side trial is ever granted here.** Every provider already gets
 * a 14-day card-less trial at sign-up (RegisterProvider), enforced app-side
 * (`useTrialGate` / `ProcessTrialExpirations`). Reaching Checkout means
 * converting to paid, so the session is always an immediate charge — Stripe's
 * hosted page reads "Subscribe" / amount due today, never "N days free". See
 * .claude/rules/plan.md, "Checkout never grants a Stripe-side trial".
 */
final class CreateCheckoutSession
{
    public function __construct(
        private readonly StripePriceCatalog $prices,
        private readonly BillingProvider $billing,
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    public function handle(User $user, Plan $plan, BillingInterval $interval): CheckoutSession
    {
        $providerId = (int) $user->provider_id;
        $priceId = $this->prices->priceIdFor($plan, $interval);
        $subscription = $this->subscriptions->findByProviderId($providerId);

        // One base URL (`STRIPE_RETURN_URL` = the frontend's `/checkout/success`
        // route), two distinct outcomes. Success carries Stripe's own
        // `{CHECKOUT_SESSION_ID}` placeholder so the landing page can reconcile
        // the session (see ReconcileCheckoutSession); cancel carries
        // `?canceled=1` so the same route can tell "backed out" from "paid".
        $baseUrl = rtrim((string) config('services.stripe.return_url'), '/');

        return $this->billing->createCheckoutSession(new CheckoutSessionRequest(
            priceId: $priceId,
            providerId: (string) $providerId,
            successUrl: $baseUrl . '?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: $baseUrl . '?canceled=1',
            customerId: $subscription?->stripe_customer_id,
            customerEmail: $subscription?->stripe_customer_id === null ? $user->email : null,
        ));
    }
}
