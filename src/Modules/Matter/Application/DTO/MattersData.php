<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\DTO;

use Illuminate\Foundation\Http\FormRequest;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

class MattersData
{
	public function __construct(
		public ?int $id,
		public ?int $provider_id,
		// Nullable: a request may have no resolvable workspace context (a
		// provider with 2+ workspaces and no active selection, or a caller
		// with none). BelongsToWorkspace's `creating` hook then fills it
		// from context/parent. See .claude/rules/workspace.md.
		public ?int $workspace_id,
		public int $client_id,
		public string $name,
		public ?string $description,
		public string $status,
		public ?string $start_date,
		public ?string $due_date,
		public ?int $assigned_staff_id = null,
	)
	{}

	public static function fromRequest(int $providerId, ?int $workspaceId, FormRequest $request)
	{
		$data = $request->validated();

		return new self(
			id: $data['id'],
			provider_id: $providerId,
			workspace_id: $workspaceId,
			client_id: $data['client_id'],
			name: $data['name'],
			description: $data['description'],
			status: $data['status'],
			start_date: $data['start_date'],
			due_date: $data['due_date'],
			assigned_staff_id: $data['assigned_staff_id'] ?? null,
		);
	}

	/**
	 * Build the full DTO for an update from the matter's current values,
	 * overlaying only the fields actually present in a (possibly partial)
	 * validated request body. Keeps matter update on the same single
	 * create-or-update path (`MattersRepository::upsert()`) rather than a
	 * second write path — see .claude/rules/matter.md.
	 *
	 * `provider_id` / `workspace_id` / `client_id` are never taken from the
	 * request here: they are the matter's own immutable scoping, and the
	 * upsert key matches on them.
	 *
	 * @param array<string, mixed> $overrides the validated request body
	 */
	public static function fromMatter(Matter $matter, array $overrides): self
	{
		$has = static fn (string $key): bool => array_key_exists($key, $overrides);

		return new self(
			id: $matter->id,
			provider_id: (int) $matter->provider_id,
			workspace_id: $matter->workspace_id !== null ? (int) $matter->workspace_id : null,
			client_id: (int) $matter->client_id,
			name: $has('name') ? $overrides['name'] : $matter->name,
			description: $has('description') ? $overrides['description'] : $matter->description,
			status: $has('status') ? $overrides['status'] : $matter->status,
			start_date: $has('start_date')
				? $overrides['start_date']
				: $matter->start_date?->toDateString(),
			due_date: $has('due_date')
				? $overrides['due_date']
				: $matter->due_date?->toDateString(),
			assigned_staff_id: $has('assigned_staff_id')
				? ($overrides['assigned_staff_id'] !== null ? (int) $overrides['assigned_staff_id'] : null)
				: ($matter->assigned_staff_id !== null ? (int) $matter->assigned_staff_id : null),
		);
	}
}
