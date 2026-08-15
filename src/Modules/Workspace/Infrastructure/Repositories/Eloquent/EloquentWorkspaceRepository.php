<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Workspace\Infrastructure\Repositories\Eloquent;

use PactTraceSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;
use PactTraceSDK\SharedResources\Modules\Workspace\Infrastructure\Repositories\BaseRepository;
use PactTraceSDK\SharedResources\Modules\Workspace\Models\Workspace;

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
