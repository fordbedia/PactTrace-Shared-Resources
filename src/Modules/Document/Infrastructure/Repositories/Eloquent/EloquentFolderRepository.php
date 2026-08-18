<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent;

use Illuminate\Support\Collection;
use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository\FolderRepository;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Repositories\BaseRepository;
use PactTraceSDK\SharedResources\Modules\Document\Models\Folder;

class EloquentFolderRepository extends BaseRepository implements FolderRepository
{
	public function create(array $data): Folder
	{
		return $this->model->create($data);
	}

	public function allForProvider(int $providerId): Collection
	{
		return $this->model->newQuery()
			->where('provider_id', $providerId)
			->orderBy('name')
			->get();
	}

	public function makeModel(): string
	{
		return Folder::class;
	}
}