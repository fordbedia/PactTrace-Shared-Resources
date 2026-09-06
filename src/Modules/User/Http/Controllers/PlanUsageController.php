<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\GetPlanUsageSummary;
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
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = Plan::tryFrom((string) $user->provider?->plan) ?? Plan::default();

        return response()->json([
            'usage' => $this->usageSummary->handle((int) $user->provider_id)->toArray(),
            'limits' => $plan->info()->toArray(),
        ]);
    }
}
