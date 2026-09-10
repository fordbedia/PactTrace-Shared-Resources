<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Services;

use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\GetPlanUsageSummary;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\EffectivePlan;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GatedAction;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanGateResult;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The one call site every controller uses to ask "is this actor's tenant
 * allowed to do X right now" — resolves the acting user's plan/subscription/
 * usage and hands them to {@see PlanPolicy}. See
 * App\Http\Concerns\EnforcesPlanGate for the HTTP-layer wrapper every
 * plan-gated controller calls, and .claude/rules/plan.md for the four wired
 * actions.
 */
final class PlanGate
{
    public function __construct(
        private readonly GetPlanUsageSummary $usageSummary,
        private readonly PlanPolicy $policy,
    ) {
    }

    public function check(GatedAction $action, User $user): PlanGateResult
    {
        $provider = $user->provider;
        $subscription = $provider?->subscription;

        // The plan whose limits apply *now* — the pending (lower) tier the
        // moment a Portal downgrade is scheduled. See Domain\Services\EffectivePlan.
        $effective = EffectivePlan::resolve(
            $provider?->plan,
            $subscription?->pending_plan,
            $subscription?->pending_plan_effective_at?->toIso8601String(),
        );

        return $this->policy->evaluate(
            $action,
            $effective->plan,
            $subscription?->status,
            $this->usageSummary->handle((int) $user->provider_id),
            $effective->viaPendingDowngrade,
            $subscription?->pending_plan_effective_at?->toFormattedDateString(),
        );
    }
}
