<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\DTO;

use Illuminate\Http\Request;

/**
 * `sort`/`direction` back the "Sort" filter chip on /dashboard/matters (see
 * .claude/rules/matter.md, "Sort") — an allow-listed column name and a
 * clamped direction, never passed through to `orderBy()` unvalidated.
 * `null` `sort` means "no explicit sort" — EloquentMattersRepository falls
 * back to its pre-existing `latest()` (by `created_at`) in that case, same
 * as before this feature existed.
 */
final readonly class MattersListData
{
	/** @var list<string> */
	public const ALLOWED_SORTS = ['name', 'due_date', 'status', 'updated_at'];

	public function __construct(
		public int $provider_id,
		public string $filter,
		public int $per_page,
		public ?int $page,
		public ?int $client_id = null,
		public bool $archived = false,
		public ?string $sort = null,
		public string $direction = 'asc',
	)
	{}

	public static function fromRequest(Request $request, int $provider_id): self
	{
		$sort = (string) $request->query('sort', '');

		return new self(
			$provider_id,
			(string) $request->query('filter', 'all'),
			(int) $request->query('per_page', 15),
			$request->filled('page') ? (int) $request->query('page') : null,
			$request->filled('client_id') ? (int) $request->query('client_id') : null,
			$request->boolean('archived'),
			in_array($sort, self::ALLOWED_SORTS, true) ? $sort : null,
			(string) $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc',
		);
	}
}
