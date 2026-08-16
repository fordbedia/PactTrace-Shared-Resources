<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\Eloquent;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTraceSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\BaseRepository;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;

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
}