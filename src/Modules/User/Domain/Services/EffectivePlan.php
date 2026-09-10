<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;

/**
 * "Which plan's limits apply to this tenant *right now*" — the one place that
 * question is answered.
 *
 * A downgrade started in the Stripe Customer Portal is not applied
 * immediately; Stripe schedules it to the end of the billing period. PactTrack
 * enforces the **pending (lower) plan's** limits from the moment the schedule
 * is created (decided with Ed) — so a tenant who asks to drop Firm → Starter
 * is held to Starter's 5 GB / 10 clients / 1 seat straight away, with the
 * denial modal explaining why. See .claude/rules/plan.md, "Pending downgrade".
 *
 * `pending_plan` only ever holds a *lower* tier (Stripe applies upgrades
 * immediately, no schedule), but this resolver still checks the rank so a
 * stale/garbage value can never *raise* the effective plan.
 *
 * Framework-free by the hexagonal rule in CLAUDE.md — a plain readonly VO
 * with a static resolver, same shape as WorkspaceLabels.
 */
final class EffectivePlan
{
    private function __construct(
        public readonly Plan $plan,
        public readonly bool $viaPendingDowngrade,
        /** ISO-8601, when {@see $viaPendingDowngrade}; null otherwise. */
        public readonly ?string $effectiveAtIso,
    ) {
    }

    public static function resolve(
        ?string $currentPlan,
        ?string $pendingPlan = null,
        ?string $pendingEffectiveAtIso = null,
    ): self {
        $current = Plan::tryFrom((string) $currentPlan) ?? Plan::default();
        $pending = $pendingPlan !== null ? Plan::tryFrom($pendingPlan) : null;

        if ($pending !== null && $pending->isLowerThan($current)) {
            return new self($pending, true, $pendingEffectiveAtIso);
        }

        return new self($current, false, null);
    }
}
