<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\NoStripeCustomerException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * `GET /billing/portal-session` — the one code path to Stripe's Customer
 * Portal (payment method, invoice history, cancellation only; plan changes
 * are deliberately kept out of the Portal — see .claude/rules/plan.md and
 * the Stripe billing pass's own Part 3, "configure the Portal to disallow
 * plan switching entirely").
 */
final class CreateBillingPortalSession
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly BillingProvider $billing,
    ) {
    }

    public function handle(User $user): string
    {
        $subscription = $this->subscriptions->findByProviderId((int) $user->provider_id);

        if ($subscription?->stripe_customer_id === null) {
            throw new NoStripeCustomerException(
                'This tenant has no Stripe customer yet — start Checkout first.'
            );
        }

        return $this->billing->createBillingPortalSession(
            $subscription->stripe_customer_id,
            (string) config('services.stripe.manage_subscriptions_url'),
        );
    }
}
