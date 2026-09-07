<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Infrastructure\Repositories\Eloquent;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;
use PactTrackSDK\SharedResources\Modules\Workspace\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

class EloquentWorkspaceRepository extends BaseRepository implements WorkspaceRepository
{
    public function makeModel(): string
    {
        return Workspace::class;
    }

    public function create(array $data): Workspace
    {
        return $this->model->create($data);
    }

    public function saveAttributes(Workspace $workspace, array $data): Workspace
    {
        $workspace->fill($data)->save();

        return $workspace->refresh();
    }

    public function forProvider(int $providerId): Collection
    {
        return Workspace::query()
            ->where('provider_id', $providerId)
            ->orderBy('name')
            ->get();
    }

    public function search(int $providerId, ?string $search = null, ?int $limit = null): Collection
    {
        $query = Workspace::query()
            ->where('provider_id', $providerId)
            // Primary first (the one RegisterProvider stamps at sign-up), then
            // by name. `is_primary` is a 0/1 column, so DESC puts the 1 on top.
            ->orderByDesc('is_primary')
            ->orderBy('name');

        $search = $search === null ? null : trim($search);

        if ($search !== null && $search !== '') {
            // LIKE with escaped wildcards — a case-insensitive substring match
            // under MySQL's default (ci) collation, tenant-scoped by the
            // provider_id above so another provider's workspace can't leak in.
            $escaped = addcslashes($search, '%_\\');
            $query->where('name', 'like', "%{$escaped}%");
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function forProviderIncludingDeactivated(int $providerId): Collection
    {
        return Workspace::withTrashed()
            ->where('provider_id', $providerId)
            ->orderBy('name')
            ->get();
    }

    public function findWithTrashed(int $workspaceId): ?Workspace
    {
        return Workspace::withTrashed()->find($workspaceId);
    }

    public function restore(Workspace $workspace): Workspace
    {
        if ($workspace->trashed()) {
            $workspace->restore();
        }

        return $workspace->refresh();
    }

    public function belongsToProvider(int $workspaceId, int $providerId): bool
    {
        // Workspace `use SoftDeletes`, so a deactivated workspace is excluded
        // by the model's default scope here — a stale pointer at one reads as
        // "does not belong", which is exactly the intended fallback behaviour.
        return Workspace::query()
            ->whereKey($workspaceId)
            ->where('provider_id', $providerId)
            ->exists();
    }
}
