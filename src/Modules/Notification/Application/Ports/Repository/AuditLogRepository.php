<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\AuditLogListData;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;

/**
 * Read-only access to the compliance audit trail — see
 * .claude/rules/notification.md. There is deliberately no write method here:
 * rows are written by each module's own use cases (Document/Signature/User),
 * and a log the HTTP layer can append to on demand is not evidence of
 * anything.
 */
interface AuditLogRepository
{
    /**
     * Paginated, filtered `audit_logs` for one tenant, newest first.
     *
     * `$data->provider_id` is applied as a hard `where` in the query itself —
     * `AuditLogPolicy::viewAny()` checks the permission only, so this is the
     * one place tenant isolation is actually enforced for the listing. Rows
     * with a null `provider_id` (system-initiated) belong to no tenant and
     * are never returned.
     */
    public function paginateFiltered(AuditLogListData $data): LengthAwarePaginator;

    /**
     * The distinct `action` strings present for this tenant, ascending — backs
     * the frontend's "Action Type" filter, which has no fixed catalogue to
     * draw from (see .claude/rules/notification.md).
     *
     * @return list<string>
     */
    public function distinctActions(int $providerId): array;

    /**
     * The newest `$limit` audit rows for one tenant — the `/dashboard`
     * "Recent Activity" timeline. A thin, small-limit read over the same
     * `audit_logs` table and the same tenant scoping as
     * `paginateFiltered()`, deliberately not a second activity-feed
     * implementation. `user` is eager-loaded like the paginated listing;
     * null-`provider_id` (system-initiated) rows belong to no tenant and are
     * excluded for free by the `where provider_id = ?`.
     *
     * @return Collection<int, AuditLog>
     */
    public function recentForProvider(int $providerId, int $limit): Collection;
}
