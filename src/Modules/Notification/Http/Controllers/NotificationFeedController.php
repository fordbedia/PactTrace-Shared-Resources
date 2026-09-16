<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Action\ListNotificationFeedHandler;
use PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases\ClearNotifications;
use PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases\MarkNotificationRead;
use PactTrackSDK\SharedResources\Modules\Notification\Http\Resources\NotificationItemResource;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;

/**
 * HTTP surface for the notification bell in AppShell's Topbar — see
 * .claude/rules/notification.md. Gated on the same `AuditLogPolicy::viewAny`
 * permission as /dashboard/audit-log (already granted to both Owner and
 * Staff): the feed is a read model over the same rows, so anyone who can see
 * the audit log can see its notification-shaped subset.
 */
class NotificationFeedController extends Controller
{
    /** Matches the "limit to 10, load more on scroll" requirement. */
    private const PER_PAGE = 10;

    public function index(Request $request, ListNotificationFeedHandler $handler)
    {
        Gate::authorize('viewAny', AuditLog::class);

        $user = $request->user();
        $page = $request->filled('page') ? (int) $request->query('page') : null;

        $paginator = $handler->handle($user->provider_id, $user->id, self::PER_PAGE, $page);

        return response()->json([
            'data' => NotificationItemResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->currentPage() < $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * `{auditLog}` is a raw id, not a route-model binding — the row still has
     * to be resolved through `AuditLog::query()` here so its own tenant +
     * WorkspaceScope narrowing applies, and an id outside the actor's tenant
     * (or one that does not exist at all) 404s.
     *
     * `AuditLogPolicy::viewAny` is a bare permission check with no record, so
     * it cannot do this on its own — without the lookup below, any signed-in
     * user holding `audit-log.view` could write a `notification_reads` row
     * against another tenant's `audit_logs` id, and a non-existent id would
     * surface as a 500 from the foreign key rather than a 404.
     */
    public function markRead(Request $request, int $auditLog, MarkNotificationRead $useCase)
    {
        Gate::authorize('viewAny', AuditLog::class);

        $user = $request->user();

        $record = AuditLog::query()
            ->where('provider_id', $user->provider_id)
            ->find($auditLog);

        abort_if($record === null, 404);

        $useCase->handle($user->id, $record->id);

        return response()->json(['data' => ['id' => $record->id, 'read' => true]]);
    }

    public function clear(Request $request, ClearNotifications $useCase)
    {
        Gate::authorize('viewAny', AuditLog::class);

        $user = $request->user();
        $useCase->handle($user->id, $user->provider_id);

        return response()->json(['data' => []]);
    }
}
