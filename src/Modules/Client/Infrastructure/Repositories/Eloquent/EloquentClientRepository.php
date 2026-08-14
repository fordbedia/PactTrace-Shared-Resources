<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Infrastructure\Repositories\Eloquent;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTraceSDK\SharedResources\Modules\Client\Infrastructure\Repositories\BaseRepository;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;

class EloquentClientRepository extends BaseRepository implements ClientRepository
{
	public function upsert(ClientData $data): Client
	{
		return $this->model->updateOrCreate([
			'provider_id' => $data->provider_id,
			'user_id' => $data->user_id,
			'email' => $data->email,
		],[
			'name' => $data->name,
			'company_name' => $data->company_name,
			'phone' => $data->phone
		]);
	}

	public function paginateAll(int $providerId, int $perPage, ?int $page): LengthAwarePaginator
	{
		return $this->paginateByStatus($providerId, null, $perPage, $page);
	}

	public function paginateActive(int $providerId, int $perPage, ?int $page): LengthAwarePaginator
	{
		return $this->paginateByStatus($providerId, 'active', $perPage, $page);
	}

	public function paginateInvited(int $providerId, int $perPage, ?int $page): LengthAwarePaginator
	{
		return $this->paginateByStatus($providerId, 'invited', $perPage, $page);
	}

	public function paginateArchived(int $providerId, int $perPage, ?int $page): LengthAwarePaginator
	{
		return $this->paginateByStatus($providerId, 'archived', $perPage, $page);
	}

	private function paginateByStatus(int $providerId, ?string $status, int $perPage, ?int $page): LengthAwarePaginator
	{
		$query = $this->model->newQuery()
			->where('provider_id', $providerId)
			->latest();

		if ($status !== null) {
			$query->where('status', $status);
		}

		return $this->paginate($query, $perPage, ['*'], 'page', $page);
	}

	public function makeModel(): string
	{
		return Client::class;
	}
}