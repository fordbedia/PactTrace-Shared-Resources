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
        $returnUrl = (string) config('services.stripe.return_url');

        return $this->billing->createCheckoutSession(new CheckoutSessionRequest(
            priceId: $priceId,
            providerId: (string) $providerId,
            successUrl: $returnUrl,
            cancelUrl: $returnUrl,
            customerId: $subscription?->stripe_customer_id,
            customerEmail: $subscription?->stripe_customer_id === null ? $user->email : null,
        ));
    }
}
