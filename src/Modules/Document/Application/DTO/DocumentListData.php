<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Application\DTO;

use Illuminate\Http\Request;

/**
 * The query side of the Document Center table on /dashboard/documents —
 * which folder is focused, and which page of it to return. Mirrors
 * MattersListData (see .claude/rules/matter.md): the request is parsed once,
 * here, so ListDocumentsAction and the repository below it never touch an
 * Illuminate\Http\Request.
 *
 * `per_page` is clamped rather than trusted — a tenant is expected to
 * eventually hold thousands of documents, and an unbounded `?per_page=100000`
 * would defeat the point of paginating at all.
 */
final readonly class DocumentListData
{
	public const DEFAULT_PER_PAGE = 15;

	public const MAX_PER_PAGE = 100;

	public function __construct(
		public ?int $folder_id,
		public int $per_page,
		public ?int $page,
	)
	{}

	public static function fromRequest(Request $request): self
	{
		return new self(
			folder_id: $request->integer('folder_id') ?: null,
			per_page: max(1, min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE)),
			page: $request->filled('page') ? max(1, (int) $request->query('page')) : null,
		);
	}
}
