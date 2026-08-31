<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Dashboard\Application\Action\GetDashboardSummaryAction;
use PactTrackSDK\SharedResources\Modules\Dashboard\Application\Action\GetRecentActivityAction;
use PactTrackSDK\SharedResources\Modules\Dashboard\Application\Action\GetRecentDocumentsAction;
use PactTrackSDK\SharedResources\Modules\Dashboard\Application\DTO\RecentDocumentsQuery;
use PactTrackSDK\SharedResources\Modules\Dashboard\Http\Resources\DashboardSummaryResource;
use PactTrackSDK\SharedResources\Modules\Document\Http\Resources\DocumentResource;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Notification\Http\Resources\AuditLogResource;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;

/**
 * Read-only HTTP surface behind `/dashboard` (the provider-side overview).
 * Thin — each method authorizes, resolves the acting provider from the
 * authenticated user, and hands off to one Application action.
 *
 * The dashboard is provider-side only. `Role::Client` holds `matter.view`
 * (a client sees their own engagement), and `viewAny` gates check the
 * permission without a row to tenant-scope against — so the permission
 * check alone would let a client-role user pull provider-wide aggregates.
 * `abort_unless($user->isProviderSide(), 403)` is the real barrier here;
 * the `Gate::authorize` calls stay for defence-in-depth and parity with
 * the rest of the codebase.
 */
class DashboardController extends Controller
{
    public function summary(Request $request, GetDashboardSummaryAction $action)
    {
        $user = $this->providerSideUser($request);
        Gate::authorize('viewAny', Matter::class);

        return new DashboardSummaryResource($action->handle($user));
    }

    public function recentDocuments(Request $request, GetRecentDocumentsAction $action)
    {
        $user = $this->providerSideUser($request);
        Gate::authorize('viewAny', Document::class);

        $query = RecentDocumentsQuery::fromRequest($request, (int) $user->provider_id);

        return DocumentResource::collection($action->handle($query));
    }

    public function recentActivity(Request $request, GetRecentActivityAction $action)
    {
        $user = $this->providerSideUser($request);
        Gate::authorize('viewAny', AuditLog::class);

        return AuditLogResource::collection($action->handle((int) $user->provider_id));
    }

    private function providerSideUser(Request $request)
    {
        $user = $request->user();

        abort_unless($user !== null && $user->provider_id !== null && $user->isProviderSide(), 403);

        return $user;
    }
}
