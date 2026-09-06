<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\GetPlanUsageSummary;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\NoStripeCustomerException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\PlanChangeBlockedException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\StripePriceCatalog;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanChangePolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\BillingInterval;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * `POST /billing/change-plan` — the only path (besides initial Checkout)
 * that ever changes a subscription's plan, upgrade or downgrade alike. Runs
 * the usage pre-flight {@see PlanChangePolicy} before ever calling Stripe;
 * on a pass, asks Stripe to update the subscription's price but does NOT
 * write `subscriptions.plan` itself — the resulting
 * `customer.subscription.updated` webhook (SyncSubscriptionFromStripe) is
 * still the only writer of that column. See .claude/rules/plan.md,
 * "Downgrade / over-limit policy".
 */
final class ChangeSubscriptionPlan
{
    private const PRORATION_BEHAVIOR = 'create_prorations';

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly StripePriceCatalog $prices,
        private readonly BillingProvider $billing,
        private readonly GetPlanUsageSummary $usageSummary,
        private readonly PlanChangePolicy $policy,
    ) {
    }

    /**
     * @throws NoStripeCustomerException  when the tenant has no live Stripe
     *                                    subscription to change yet (the
     *                                    frontend should route them to
     *                                    Checkout instead)
     * @throws PlanChangeBlockedException when current usage exceeds the
     *                                    target plan's limits
     */
    public function handle(User $user, Plan $targetPlan): void
    {
        $providerId = (int) $user->provider_id;
        $subscription = $this->subscriptions->findByProviderId($providerId);

        if ($subscription?->stripe_subscription_id === null) {
            throw new NoStripeCustomerException(
                'This tenant has no active Stripe subscription to change — start Checkout first.'
            );
        }

        $result = $this->policy->evaluate($targetPlan, $this->usageSummary->handle($providerId));

        if (! $result->allowed) {
            throw new PlanChangeBlockedException($result);
        }

        // Preserve whichever billing interval the tenant is already on —
        // this endpoint only ever carries a target plan, not an interval.
        $currentMapping = $subscription->stripe_price_id !== null
            ? $this->prices->resolve($subscription->stripe_price_id)
            : null;
        $interval = $currentMapping?->interval ?? BillingInterval::Monthly;

        $this->billing->updateSubscriptionPrice(
            $subscription->stripe_subscription_id,
            $this->prices->priceIdFor($targetPlan, $interval),
            self::PRORATION_BEHAVIOR,
        );
    }
}
