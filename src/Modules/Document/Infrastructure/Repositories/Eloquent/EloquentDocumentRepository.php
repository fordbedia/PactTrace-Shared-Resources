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

	public function forProvider(int $providerId, ?int $clientId, int $perPage, ?int $page, bool $archived = false): LengthAwarePaginator
	{
		$query = $this->model->newQuery()
			->where('provider_id', $providerId)
			->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
			->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
			->with(['uploader', 'matter'])
			->latest();

		return $this->paginate($query, $perPage, ['*'], 'page', $page);
	}

	public function forFolders(int $providerId, array $folderIds, ?int $clientId, int $perPage, ?int $page, bool $archived = false): LengthAwarePaginator
	{
		$query = $this->model->newQuery()
			->where('provider_id', $providerId)
			->whereIn('folder_id', $folderIds)
			->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
			->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
			->with(['uploader', 'matter'])
			->latest();

		return $this->paginate($query, $perPage, ['*'], 'page', $page);
	}

	public function totalSizeForProvider(int $providerId, ?int $clientId = null): int
	{
		// sum() returns null on an empty set and a numeric string on some
		// drivers — cast rather than trusting the return type.
		return (int) $this->model->newQuery()
			->where('provider_id', $providerId)
			->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
			->sum('size');
	}

	public function save(Document $document, array $attributes): Document
	{
		$document->fill($attributes)->save();

		return $document->refresh();
	}

	public function delete(Document $document): void
	{
		$document->delete();
	}

	public function makeModel(): string
	{
		return Document::class;
	}
}
