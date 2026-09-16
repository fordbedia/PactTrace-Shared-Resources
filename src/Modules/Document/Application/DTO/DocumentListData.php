<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\DTO;

use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Services\DocumentFileType;

/**
 * The query side of the Document Center table on /dashboard/documents —
 * which folder is focused, which filters narrow it, and which page of it to
 * return. Mirrors MattersListData (see .claude/rules/matter.md): the request
 * is parsed once, here, so ListDocumentsAction and the repository below it
 * never touch an Illuminate\Http\Request.
 *
 * `per_page` is clamped rather than trusted — a tenant is expected to
 * eventually hold thousands of documents, and an unbounded `?per_page=100000`
 * would defeat the point of paginating at all.
 *
 * `matter_id`/`client_id` are exposed both as bare top-level fields (needed
 * by ListDocumentsAction to decide whether a request is the Matter Detail
 * page's flat, folder-agnostic listing — see that class) AND folded into
 * `filters` (the folder-scoped narrowing DocumentRepository::forProvider()/
 * forFolders() actually apply). `file_type[]`/`date_from`/`date_to`/`search`
 * only ever apply to the folder-scoped path, so they live on `filters` only.
 */
final readonly class DocumentListData
{
	public const DEFAULT_PER_PAGE = 15;

	public const MAX_PER_PAGE = 100;

	/** `Y-m-d` — a plain calendar date, matched against `documents.created_at`. */
	private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

	public function __construct(
		public ?int $folder_id,
		public int $per_page,
		public ?int $page,
		public bool $archived = false,
		public ?int $matter_id = null,
		public ?int $client_id = null,
		public DocumentFilters $filters = new DocumentFilters(),
	)
	{}

	public static function fromRequest(Request $request): self
	{
		$matterId = $request->integer('matter_id') ?: null;
		$clientId = $request->integer('client_id') ?: null;

		return new self(
			folder_id: $request->integer('folder_id') ?: null,
			per_page: max(1, min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE)),
			page: $request->filled('page') ? max(1, (int) $request->query('page')) : null,
			archived: $request->boolean('archived'),
			matter_id: $matterId,
			client_id: $clientId,
			filters: new DocumentFilters(
				matterId: $matterId,
				clientId: $clientId,
				fileTypes: self::parseFileTypes($request),
				dateFrom: self::parseDate($request, 'date_from'),
				dateTo: self::parseDate($request, 'date_to'),
				search: $request->filled('search') ? trim((string) $request->query('search')) : null,
			),
		);
	}

	/** @return list<string> only recognised buckets — an unrecognised value is dropped rather than 500ing. */
	private static function parseFileTypes(Request $request): array
	{
		$raw = $request->query('file_type', []);
		$raw = is_array($raw) ? $raw : [$raw];

		return array_values(array_intersect(
			array_map('strval', $raw),
			DocumentFileType::values(),
		));
	}

	/** An invalid date string is ignored rather than passed through to raw SQL. */
	private static function parseDate(Request $request, string $key): ?string
	{
		$value = (string) $request->query($key, '');

		return preg_match(self::DATE_PATTERN, $value) === 1 ? $value : null;
	}
}
