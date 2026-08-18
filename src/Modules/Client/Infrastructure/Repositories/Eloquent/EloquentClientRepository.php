<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Infrastructure\Repositories\Eloquent;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTraceSDK\SharedResources\Modules\Client\Infrastructure\Repositories\BaseRepository;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;

class EloquentClientRepository extends BaseRepository implements ClientRepository
{
	public function upsert(ClientData $data): Client
	{
		// Matches the table's actual unique constraint (provider_id, email) —
		// user_id is deliberately excluded from the match key. Matching on it
		// too was the original bug: it silently accepted the caller's own
		// user id as part of the identity of "this client," so re-inviting
		// the same email as a different staff member could collide with the
		// unique index instead of updating the existing row.
		$attributes = [
			'provider_id' => $data->provider_id,
			'email' => $data->email,
		];

		$values = [
			'name' => $data->name,
			'company_name' => $data->company_name,
			'phone' => $data->phone,
		];

		// Only ever set when explicitly provided. In particular, never let a
		// later invite/edit null out a user_id that AcceptClientInvitation
		// already linked — ClientData::user_id is null on every ordinary
		// invite, precisely so this can't happen.
		if ($data->user_id !== null) {
			$values['user_id'] = $data->user_id;
		}

		return $this->model->updateOrCreate($attributes, $values);
	}

	public function attachUser(int $clientId, int $userId): Client
	{
		$client = $this->model->newQuery()->findOrFail($clientId);

		$client->forceFill([
			'user_id' => $userId,
			'status' => 'active',
		])->save();

		return $client;
	}

	public function searchForSelection(int $providerId, string $search, int $limit): Collection
	{
		$query = $this->model->newQuery()->where('provider_id', $providerId);

		if ($search !== '') {
			$query->where(function ($clientQuery) use ($search) {
				$clientQuery->where('name', 'like', "%{$search}%")
					->orWhere('company_name', 'like', "%{$search}%")
					->orWhere('email', 'like', "%{$search}%");
			});
		}

		return $query->orderBy('name')->limit($limit)->get();
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