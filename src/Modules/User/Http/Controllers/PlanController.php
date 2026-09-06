<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;

/**
 * The plan catalogue — every tier's {@see \PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanInfo}
 * in one payload, so `/dashboard/billing`'s plan-comparison grid reads the
 * real matrix instead of a hand-maintained array that can drift from it.
 *
 * Read-only, no policy: what each plan allows is not tenant-specific. Behind
 * `auth:sanctum` only because every SPA surface is. Pricing is deliberately
 * not here — it lives with the marketing copy / Stripe, not in `PlanInfo`.
 */
final class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (Plan $plan): array => $plan->info()->toArray(),
                Plan::cases(),
            ),
        ]);
    }
}
