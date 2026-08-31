<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository;

use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

interface MattersRepository
{
	/**
	 * Persist changes to an already-resolved matter (the Matter Detail
	 * page's edit / assign-staff flow). Distinct from `upsert()`'s
	 * create-or-update-by-key: the caller already holds the row, so this
	 * updates that instance directly and never risks minting a duplicate
	 * when a scoping column (e.g. a legacy null `workspace_id`) doesn't
	 * match the key.
	 */
	public function updateMatter(Matter $matter, MattersData $data): Matter;

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

	/**
	 * Matters created at or after `$since` for one tenant, any status — the
	 * "+N this week" delta on the `/dashboard` "Active Matters" card. A
	 * creation count, not a status count: it answers "how many new matters
	 * did this provider open recently", which is what the artboard's trend
	 * indicator means.
	 */
	public function countCreatedSince(int $providerId, DateTimeInterface $since): int;

	/**
	 * The tenant's in-progress matters (status `active` or `on_hold`) for the
	 * `/dashboard` "Matters in Progress" panel — soonest `due_date` first
	 * (matters with no due date last), then most-recently-updated. Eager-loads
	 * `client`, `milestones` (so MatterProgressCalculator reuses them without
	 * an N+1) and `assignedStaff`, matching MatterResource's expectations.
	 */
	public function inProgressForProvider(int $providerId, int $limit): Collection;
}