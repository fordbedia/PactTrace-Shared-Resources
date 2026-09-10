<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Services;

use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\CreateBillingPortalSession;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\GetPlanUsageSummary;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\EffectivePlan;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PortalDowngradeAvailability;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Picks which Stripe Billing Portal configuration a session should open with
 * — the permissive one (all plan switches) or the downgrade-locked one — for
 * one acting user, right before {@see CreateBillingPortalSession}
 * calls Stripe. See .claude/rules/plan.md, "Portal configuration swap".
 *
 * Rule (matches `PlanChangePolicy`, no second implementation): the tenant
 * gets the **restricted** configuration only when their current usage fits
 * *no* tier below their current plan. If even one lower tier still fits they
 * keep the permissive Portal — the immediate pending-downgrade enforcement
 * (see {@see PlanGate}) is the real guard for whatever they pick there.
 *
 * Resolves against the *stored* plan, not {@see EffectivePlan}:
 * once a downgrade is already scheduled the question "can they schedule a
 * downgrade" is moot, and holding them to the pending tier here would let a
 * tenant re-open the Portal and switch freely the moment the pending tier
 * happens to fit.
 */
final class ResolvePortalConfiguration
{
    public function __construct(
        private readonly GetPlanUsageSummary $usageSummary,
        private readonly PortalDowngradeAvailability $downgradeAvailability,
    ) {}

    /**
     * The `bpc_...` id to hand {@see BillingProvider::createBillingPortalSession()},
     * or null when neither configuration is set (the adapter then falls back
     * to its own default — behaviour unchanged from before this feature).
     */
    public function resolveFor(User $user): ?string
    {
        $default = $this->configId('billing_portal_configuration_id');
        $restricted = $this->configId('billing_portal_configuration_id_restricted');

        // No restricted configuration provisioned yet -> nothing to swap to.
        if ($restricted === null) {
            return $default;
        }

        $current = Plan::tryFrom((string) $user->provider?->plan) ?? Plan::default();

        // Lowest tier already — there is no downgrade to lock.
        if ($current === Plan::Starter) {
            return $default;
        }

        $usage = $this->usageSummary->handle((int) $user->provider_id);

        return $this->downgradeAvailability->anyLowerTierFits($current, $usage)
            ? $default
            : $restricted;
    }

    private function configId(string $key): ?string
    {
        $value = config("services.stripe.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
