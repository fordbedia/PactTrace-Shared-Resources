<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Services;

use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\GetPlanUsageSummary;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GatedAction;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
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
        $plan = Plan::tryFrom((string) $provider?->plan) ?? Plan::default();
        $subscriptionStatus = $provider?->subscription?->status;

        return $this->policy->evaluate(
            $action,
            $plan,
            $subscriptionStatus,
            $this->usageSummary->handle((int) $user->provider_id),
        );
    }
}
