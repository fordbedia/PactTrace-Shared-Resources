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
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangePreview;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * `POST /billing/change-plan/preview` — the read-only companion to
 * {@see ChangeSubscriptionPlan}. Runs the **same** {@see PlanChangePolicy}
 * pre-flight (so the confirmation modal never opens on a change that would
 * be blocked), resolves the target price the same way, then asks
 * {@see BillingProvider::previewPlanChange()} for a non-mutating cost
 * estimate. Changes nothing.
 *
 * See .claude/rules/plan.md, "Portal configuration swap" / "Stripe status".
 */
final class PreviewSubscriptionPlanChange
{
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
     *                                    subscription to change yet
     * @throws PlanChangeBlockedException when current usage exceeds the
     *                                    target plan's limits
     */
    public function handle(User $user, Plan $targetPlan): PlanChangePreview
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

        $currentMapping = $subscription->stripe_price_id !== null
            ? $this->prices->resolve($subscription->stripe_price_id)
            : null;
        $interval = $currentMapping?->interval ?? BillingInterval::Monthly;
        $targetPriceId = $this->prices->priceIdFor($targetPlan, $interval);

        return $this->billing->previewPlanChange(
            $subscription->stripe_subscription_id,
            $targetPriceId,
        );
    }
}
