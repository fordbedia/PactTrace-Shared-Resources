<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Repositories\BaseRepository;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;

class EloquentDocumentRepository extends BaseRepository implements DocumentRepository
{
	public function create(array $data): Document
	{
		return $this->model->create($data);
	}

	public function forProvider(int $providerId, ?int $clientId, int $perPage, ?int $page): LengthAwarePaginator
	{
		$query = $this->model->newQuery()
			->where('provider_id', $providerId)
			->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
			->with(['uploader', 'matter'])
			->latest();

		return $this->paginate($query, $perPage, ['*'], 'page', $page);
	}

	public function forFolders(int $providerId, array $folderIds, ?int $clientId, int $perPage, ?int $page): LengthAwarePaginator
	{
		$query = $this->model->newQuery()
			->where('provider_id', $providerId)
			->whereIn('folder_id', $folderIds)
			->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
			->with(['uploader', 'matter'])
			->latest();

		return $this->paginate($query, $perPage, ['*'], 'page', $page);
	}

	public function makeModel(): string
	{
		return Document::class;
	}
}
