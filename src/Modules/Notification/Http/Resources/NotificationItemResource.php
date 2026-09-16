<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;

/**
 * One row in the notification bell's feed (AppShell's Topbar) — the same
 * `audit_logs` row AuditLogResource exposes on /dashboard/audit-log, pared
 * down to what the bell renders. The frontend derives the display label from
 * `action` via the shared `describeAuditAction()` helper — no label is
 * computed server-side, so the two surfaces can never disagree about copy.
 *
 * `read` is always **false** here, and that is correct rather than a stub:
 * the feed is unread-only by construction (see
 * {@see \PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository::paginateUnreadForUser()}
 * — a row this user has read is excluded by the query itself, it is never
 * returned dimmed). The field stays on the wire so the bell's bold-vs-regular
 * style rule has a defined value to read instead of `undefined`.
 *
 * @mixin AuditLog
 */
class NotificationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'read' => false,
        ];
    }
}
