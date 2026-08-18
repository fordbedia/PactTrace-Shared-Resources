<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent;

use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository\FolderRepository;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Repositories\BaseRepository;
use PactTraceSDK\SharedResources\Modules\Document\Models\Folder;

class EloquentFolderRepository extends BaseRepository implements FolderRepository
{
	public function create(array $data): Folder
	{
		return $this->model->create($data);
	}

	public function makeModel(): string
	{
		return Folder::class;
	}
}