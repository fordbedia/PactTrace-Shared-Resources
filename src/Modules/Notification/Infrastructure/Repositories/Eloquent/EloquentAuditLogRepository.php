<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Infrastructure\Repositories\Eloquent;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\AuditLogListData;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes\WorkspaceScope;

class EloquentAuditLogRepository extends BaseRepository implements AuditLogRepository
{
    public function makeModel(): string
    {
        return AuditLog::class;
    }

    public function paginateFiltered(AuditLogListData $data, ?string $retentionCutoff = null): LengthAwarePaginator
    {
        $query = $this->applyListFilters(
            $this->baseQuery($data->provider_id)->with('user.roles')->latest()->latest('id'),
            $data,
            $retentionCutoff,
        );

        return $query->paginate($data->per_page, ['*'], 'page', $data->page);
    }

    /**
     * @see AuditLogRepository::paginateForClient()
     */
    public function paginateForClient(int $providerId, int $clientId, AuditLogListData $data, ?string $retentionCutoff = null): LengthAwarePaginator
    {
        $query = $this->applyListFilters(
            $this->clientScopedQuery($providerId, $clientId)->with('user.roles')->latest()->latest('id'),
            $data,
            $retentionCutoff,
        );

        return $query->paginate($data->per_page, ['*'], 'page', $data->page);
    }

    /**
     * Every filter `paginateFiltered()`/`paginateForClient()` accept —
     * retention cutoff, action types, date range, search — applied
     * identically regardless of which base query (provider-wide or
     * client-scoped) they start from, so the two paginated listings can
     * never drift on what a given filter means.
     */
    private function applyListFilters(Builder $query, AuditLogListData $data, ?string $retentionCutoff): Builder
    {
        if ($retentionCutoff !== null) {
            // Plan-enforced retention window (Starter = 90 days; unlimited
            // for Professional/Firm, in which case the handler passes null).
            // Visibility only — older rows are kept, just not returned here.
            // See .claude/rules/plan.md.
            $query->where('created_at', '>=', $retentionCutoff);
        }

        if ($data->actions !== []) {
            $query->whereIn('action', $data->actions);
        }

        if ($data->from !== null) {
            $query->where('created_at', '>=', $data->from . ' 00:00:00');
        }

        if ($data->to !== null) {
            $query->where('created_at', '<=', $data->to . ' 23:59:59');
        }

        if ($data->search !== null) {
            $term = '%' . $data->search . '%';
            $query->where(function (Builder $inner) use ($term) {
                $inner->where('action', 'like', $term)
                    ->orWhereHas('user', function (Builder $user) use ($term) {
                        $user->where('name', 'like', $term);
                    });
            });
        }

        return $query;
    }

    /**
     * @see AuditLogRepository::paginateUnreadForUser()
     */
    public function paginateUnreadForUser(int $providerId, int $userId, int $perPage, ?int $page): LengthAwarePaginator
    {
        return $this->baseQuery($providerId)
            ->whereNotExists(function ($query) use ($userId): void {
                $query->selectRaw('1')
                    ->from('notification_reads')
                    ->whereColumn('notification_reads.audit_log_id', 'audit_logs.id')
                    ->where('notification_reads.user_id', $userId);
            })
            ->with('user.roles')
            ->latest()
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function recentForProvider(int $providerId, int $limit): Collection
    {
        return $this->baseQuery($providerId)
            ->with('user.roles')
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function distinctActions(int $providerId): array
    {
        return $this->baseQuery($providerId)
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }


    /**
     * @see AuditLogRepository::recentForClient()
     */
    public function recentForClient(int $providerId, int $clientId, int $limit): Collection
    {
        return $this->clientScopedQuery($providerId, $clientId)
            ->with('user.roles')
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Every read here is hard-scoped to one tenant. A null `provider_id` row
     * (system-initiated — see AuditLogPolicy's docblock) belongs to no tenant
     * and must never surface in a portal listing, so `where provider_id = ?`
     * excludes it for free.
     */
    private function baseQuery(int $providerId): Builder
    {
        return $this->model->newQuery()->where('provider_id', $providerId);
    }

    /**
     * The tenant-scoped base query, additionally narrowed to one client —
     * shared by `recentForClient()` (the Overview tab's small preview) and
     * `paginateForClient()` (the full, filterable Activity tab), so the two
     * can never disagree about what "this client's audit trail" means.
     * `audit_logs` has no `client_id` column of its own, so this matches any
     * row whose auditable is a Matter, Document, Envelope or MessageThread
     * belonging to `$clientId`, via `whereHasMorph` rather than a join this
     * table has no column to support. Drops `WorkspaceScope`, same as
     * `EloquentClientNotificationSignalReader`: a client's activity spans
     * every workspace they have matters in, not just whichever one is
     * currently active.
     */
    private function clientScopedQuery(int $providerId, int $clientId): Builder
    {
        return $this->baseQuery($providerId)
            ->whereHasMorph(
                'auditable',
                [Matter::class, Document::class, Envelope::class, MessageThread::class],
                fn (Builder $query) => $query->withoutGlobalScope(WorkspaceScope::class)->where('client_id', $clientId),
            );
    }
}
