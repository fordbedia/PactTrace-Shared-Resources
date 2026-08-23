<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\Eloquent;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;

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
		]);
	}

	public function makeModel(): string
	{
		return Matter::class;
	}

	public function searchClientsForSelection(int $providerId, string $search, int $limit): Collection
	{
		// Deliberately fails closed rather than relying on WorkspaceScope's
		// default fail-open behaviour: this is a live, interactive pick for a
		// workspace-scoped Matter, not a background job with no request to
		// resolve a workspace from. If the current workspace can't be resolved
		// (a provider with 2+ workspaces and no active selection yet — there's
		// no workspace switcher in the product yet), returning every
		// workspace's clients would let one get attached to the wrong
		// workspace's Matter, so we return none instead. See
		// BelongsToWorkspace::scopeWhereWorkspace() and
		// .claude/rules/workspace.md.
		$workspaceId = app(CurrentWorkspace::class)->id();

		$query = Client::query()
			->where('provider_id', $providerId)
			->whereWorkspace($workspaceId);

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
}