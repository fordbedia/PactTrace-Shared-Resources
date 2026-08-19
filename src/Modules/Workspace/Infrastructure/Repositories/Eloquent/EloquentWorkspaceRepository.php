<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Infrastructure\Repositories\Eloquent;

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
}
