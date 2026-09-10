<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanUsageSummary;

/**
 * "Given this tenant's current usage, is a downgrade to *any* lower tier
 * possible right now?" — the single fact behind both the Stripe Customer
 * Portal config-swap (a tenant for whom no downgrade fits gets the
 * downgrade-locked Portal configuration) and the `/dashboard/billing`
 * `downgrade_available` hint. See .claude/rules/plan.md, "Portal
 * configuration swap".
 *
 * Composes {@see PlanChangePolicy} — it does NOT re-check seats/clients/
 * storage itself. The rule is deliberately permissive: as long as one lower
 * tier still fits (e.g. Firm -> Professional), the tenant keeps the normal
 * Portal even if the very lowest tier (Starter) does not. Restriction only
 * kicks in when every lower tier is blocked.
 *
 * Framework-free by the hexagonal rule in CLAUDE.md — same shape as
 * {@see PlanPolicy} / {@see PlanChangePolicy}.
 */
final class PortalDowngradeAvailability
{
    public function __construct(
        private readonly PlanChangePolicy $planChangePolicy = new PlanChangePolicy,
    ) {}

    /**
     * True when a change to at least one plan strictly below `$current`
     * passes the usage pre-flight. Always true has no lower tier to test
     * against — a Starter tenant has nothing to downgrade to, so this
     * returns false (there is no downgrade to make available).
     */
    public function anyLowerTierFits(Plan $current, PlanUsageSummary $usage): bool
    {
        foreach (Plan::cases() as $candidate) {
            if ($candidate->rank() >= $current->rank()) {
                continue;
            }

            if ($this->planChangePolicy->evaluate($candidate, $usage)->allowed) {
                return true;
            }
        }

        return false;
    }
}
