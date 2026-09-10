<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\GetPlanUsageSummary;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\EffectivePlan;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PortalDowngradeAvailability;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;

/**
 * `GET /api/v1/plan-usage` — the one payload both storage indicators
 * (`/dashboard`, `/dashboard/documents`) and the frontend plan-guard hook
 * read, so "how much have we used" and "what does our plan allow" can never
 * drift apart. Read-only, no policy beyond `auth:sanctum` — every field is
 * already scoped to the caller's own tenant. See .claude/rules/plan.md.
 */
final class PlanUsageController extends Controller
{
    public function __construct(
        private readonly GetPlanUsageSummary $usageSummary,
        private readonly PortalDowngradeAvailability $downgradeAvailability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->provider?->subscription;

        // The *effective* plan — the pending (lower) tier once a Portal
        // downgrade is scheduled, so the frontend plan-guard hook pre-checks
        // against the same limits the server will actually enforce. See
        // Domain\Services\EffectivePlan and .claude/rules/plan.md.
        $plan = EffectivePlan::resolve(
            $user->provider?->plan,
            $subscription?->pending_plan,
            $subscription?->pending_plan_effective_at?->toIso8601String(),
        )->plan;

        $usage = $this->usageSummary->handle((int) $user->provider_id);
        $storedPlan = Plan::tryFrom((string) $user->provider?->plan) ?? Plan::default();

        return response()->json([
            'usage' => $usage->toArray(),
            'limits' => $plan->info()->toArray(),
            // Whether *any* lower tier still fits this tenant's usage — the
            // same fact ResolvePortalConfiguration swaps the Stripe Portal on.
            // `/dashboard/billing` shows a "downgrades paused" note when false.
            'downgrade_available' => $this->downgradeAvailability->anyLowerTierFits($storedPlan, $usage),
        ]);
    }
}
