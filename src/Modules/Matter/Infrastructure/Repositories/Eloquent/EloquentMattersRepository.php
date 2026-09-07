<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\Eloquent;

use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;

class EloquentMattersRepository extends BaseRepository implements MattersRepository
{
	public function upsert(MattersData $data)
	{
		return $this->model->updateOrCreate([
			'id' => $data->id,
			'provider_id' => $data->provider_id,
			'workspace_id' => $data->workspace_id,
			'client_id' => $data->client_id,
		], [
			'name' => $data->name,
			'description' => $data->description,
			'status' => $data->status,
			'start_date' => $data->start_date,
			'due_date' => $data->due_date,
			'assigned_staff_id' => $data->assigned_staff_id,
		]);
	}

	public function updateMatter(Matter $matter, MattersData $data): Matter
	{
		$matter->fill([
			'name' => $data->name,
			'description' => $data->description,
			'status' => $data->status,
			'start_date' => $data->start_date,
			'due_date' => $data->due_date,
			'assigned_staff_id' => $data->assigned_staff_id,
		])->save();

		return $matter;
	}

	public function makeModel(): string
	{
		return Matter::class;
	}

	public function searchClientsForSelection(int $providerId, string $search, int $limit): Collection
	{
		// Scoped to the tenant (`provider_id`) only — a Client is a
		// provider-scoped CRM record, NOT workspace-scoped (see
		// .claude/rules/client.md). It's fine to attach any of the provider's
		// clients to a Matter in any workspace: the Matter carries the
		// workspace, the Client does not. This used to call
		// `Client::whereWorkspace()` off the (now removed) `BelongsToWorkspace`
		// trait / `clients.workspace_id` column, which silently hid every
		// client with a null workspace_id.
		$query = Client::query()
			->where('provider_id', $providerId);

		if ($search !== '') {
			$query->where(function ($clientQuery) use ($search) {
				$clientQuery->where('name', 'like', "%{$search}%")
					->orWhere('company_name', 'like', "%{$search}%")
					->orWhere('email', 'like', "%{$search}%");
			});
		}

		return $query->orderBy('name')->limit($limit)->get();
	}

	public function searchForSelection(int $providerId, string $search, int $limit): Collection
	{
		// Eager-loads `client` (unlike the bare id `where('provider_id', ...)`
		// query below might suggest) because MatterResource exposes it via
		// whenLoaded('client') — the Upload Documents modal on
		// /dashboard/documents uses the selected matter's client to auto-fill
		// and lock its own Client field, so the name/company_name has to be on
		// the wire, not just client_id. See .claude/rules/document.md.
		$query = $this->model->newQuery()->with('client')->where('provider_id', $providerId);

		if ($search !== '') {
			$query->where('name', 'like', "%{$search}%");
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

	public function paginateOnHold(int $providerId, int $perPage, ?int $page): LengthAwarePaginator
	{
		return $this->paginateByStatus($providerId, 'on_hold', $perPage, $page);
	}

	public function paginateCompleted(int $providerId, int $perPage, ?int $page): LengthAwarePaginator
	{
		return $this->paginateByStatus($providerId, 'completed', $perPage, $page);
	}

	public function paginateCancelled(int $providerId, int $perPage, ?int $page): LengthAwarePaginator
	{
		return $this->paginateByStatus($providerId, 'cancelled', $perPage, $page);
	}

	private function paginateByStatus(int $providerId, ?string $status, int $perPage, ?int $page): LengthAwarePaginator
	{
		$query = $this->model->newQuery()
			->with(['client', 'milestones'])
			->where('provider_id', $providerId)
			->latest();

		if ($status !== null) {
			$query->where('status', $status);
		}

		return $this->paginate($query, $perPage, ['*'], 'page', $page);
	}

	/**
	 * Used by MatterProgressCalculator when a Matter's `milestones` relation
	 * isn't already eager-loaded (e.g. a single-record lookup outside the
	 * paginated list queries above, which always eager-load it).
	 */
	public function milestonesForMatter(int $matterId): Collection
	{
		return Milestone::query()->where('matter_id', $matterId)->orderBy('position')->get();
	}

	public function countAll(int $providerId): int
	{
		return $this->countByStatus($providerId, null);
	}

	public function countActive(int $providerId): int
	{
		return $this->countByStatus($providerId, 'active');
	}

	public function countOnHold(int $providerId): int
	{
		return $this->countByStatus($providerId, 'on_hold');
	}

	public function countCompleted(int $providerId): int
	{
		return $this->countByStatus($providerId, 'completed');
	}

	private function countByStatus(int $providerId, ?string $status): int
	{
		$query = $this->model->newQuery()->where('provider_id', $providerId);

		if ($status !== null) {
			$query->where('status', $status);
		}

		return $query->count();
	}

	public function countCreatedSince(int $providerId, DateTimeInterface $since): int
	{
		return $this->model->newQuery()
			->where('provider_id', $providerId)
			->where('created_at', '>=', $since)
			->count();
	}

	public function inProgressForProvider(int $providerId, int $limit): Collection
	{
		return $this->model->newQuery()
			->with(['client', 'milestones', 'assignedStaff'])
			->where('provider_id', $providerId)
			->whereIn('status', ['active', 'on_hold'])
			// Soonest deadline first; matters with no due date sort to the end.
			->orderByRaw('due_date IS NULL, due_date asc')
			->orderByDesc('updated_at')
			->limit($limit)
			->get();
	}
}