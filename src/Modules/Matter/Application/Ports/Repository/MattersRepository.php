<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface MattersRepository
{
	/**
	 * Clients selectable as the owner of a new matter — the "Search or select
	 * client…" field on the New Matter drawer. Scoped to the actor's tenant
	 * (`provider_id`) and, explicitly, to the current workspace via
	 * `Client::whereWorkspace()` — see .claude/rules/workspace.md. Deliberately
	 * fails closed (no current workspace ⇒ no clients) rather than falling
	 * back to `BelongsToWorkspace`'s default fail-open scope: this backs a
	 * live user pick for a workspace-scoped Matter, so leaking another
	 * workspace's clients into it would be a correctness bug, not just a
	 * background-job ambiguity.
	 */
	public function searchClientsForSelection(int $providerId, string $search, int $limit): Collection;

	/**
	 * Matters selectable as the destination for a document upload — the
	 * "Search or select matter…" field on the Upload Documents modal (see
	 * .claude/rules/document.md). Scoped to `provider_id` only, same as
	 * index()/paginateAll — relies on BelongsToWorkspace's default fail-open
	 * scope rather than searchClientsForSelection's explicit fail-closed
	 * override, since that override exists for a higher-stakes write (which
	 * workspace a new Matter permanently belongs to), not a read-only pick.
	 */
	public function searchForSelection(int $providerId, string $search, int $limit): Collection;

	/**
	 * Backs `/dashboard/matters`' filter chips (`all|active|on_hold|completed|
	 * cancelled`) — same shape as `ClientRepository::paginateAll/Active/...`
	 * (see .claude/rules/client.md). `provider_id` is the tenancy barrier;
	 * `BelongsToWorkspace` narrows further via its own global scope.
	 */
	public function paginateAll(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateActive(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateOnHold(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateCompleted(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateCancelled(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	/**
	 * Backs the "Total Matters"/"Active"/"On Hold"/"Completed" stat cards on
	 * `/dashboard/matters` — see MatterStatsService. Plain `COUNT` queries
	 * rather than reusing paginate*()->total(), which would page through a
	 * full query just to discard the rows.
	 */
	public function countAll(int $providerId): int;

	public function countActive(int $providerId): int;

	public function countOnHold(int $providerId): int;

	public function countCompleted(int $providerId): int;
}