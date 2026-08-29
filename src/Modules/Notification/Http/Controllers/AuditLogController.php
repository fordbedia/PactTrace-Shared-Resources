<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Action\ListAuditLogsHandler;
use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\AuditLogListData;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Http\Resources\AuditLogResource;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;

/**
 * Read-only HTTP surface for the compliance audit trail — backs
 * `/dashboard/audit-log`. See .claude/rules/notification.md.
 *
 * `index()` and `actionTypes()` only. There is no store/update/destroy and
 * there must not be: AuditLogPolicy has no gate for them, and a log the
 * application can rewrite on demand is not evidence of anything.
 *
 * `AuditLogPolicy::viewAny()` checks the `audit-log.view` permission but
 * cannot scope to a tenant (no row yet) — so the DTO takes `provider_id`
 * from the authenticated user and the repository query enforces isolation
 * itself. Mirrors MattersController::index().
 */
class AuditLogController extends Controller
{
    public function index(Request $request, ListAuditLogsHandler $handler)
    {
        Gate::authorize('viewAny', AuditLog::class);

        $data = AuditLogListData::fromRequest($request, auth()->user()->provider_id);

        return AuditLogResource::collection($handler->handle($data));
    }

    /**
     * Distinct `action` values present for this tenant — the "Action Type"
     * filter's option list. There is no fixed catalogue of actions in the
     * codebase, so the filter is built from what actually exists rather than
     * a hardcoded set.
     */
    public function actionTypes(AuditLogRepository $repository)
    {
        Gate::authorize('viewAny', AuditLog::class);

        return response()->json([
            'data' => $repository->distinctActions(auth()->user()->provider_id),
        ]);
    }
}
