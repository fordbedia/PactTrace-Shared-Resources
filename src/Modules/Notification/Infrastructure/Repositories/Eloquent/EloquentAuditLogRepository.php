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

class EloquentAuditLogRepository extends BaseRepository implements AuditLogRepository
{
    public function makeModel(): string
    {
        return AuditLog::class;
    }

    public function paginateFiltered(AuditLogListData $data): LengthAwarePaginator
    {
        $query = $this->baseQuery($data->provider_id)
            ->with('user')
            ->latest()
            ->latest('id');

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

        return $query->paginate($data->per_page, ['*'], 'page', $data->page);
    }

    public function recentForProvider(int $providerId, int $limit): Collection
    {
        return $this->baseQuery($providerId)
            ->with('user')
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
     * Every read here is hard-scoped to one tenant. A null `provider_id` row
     * (system-initiated — see AuditLogPolicy's docblock) belongs to no tenant
     * and must never surface in a portal listing, so `where provider_id = ?`
     * excludes it for free.
     */
    private function baseQuery(int $providerId): Builder
    {
        return $this->model->newQuery()->where('provider_id', $providerId);
    }
}
