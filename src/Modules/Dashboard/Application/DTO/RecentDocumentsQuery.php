<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Application\DTO;

use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * Query surface for `GET /api/v1/dashboard/recent-documents` — the "Recent
 * Documents" list on `/dashboard` and its All / Pending / Signed / Draft
 * filter pills.
 *
 * The pill → real `DocumentStatus` folding lives here (backend), so the
 * frontend never string-matches statuses and there is exactly one
 * canonical mapping shared with the client portal's pills
 * (`Sent`/`PartiallySigned` = pending, `Completed` = signed, `Draft` =
 * draft — see PortalDocumentStatusMapper). "Pending" folding more than one
 * real status is done here as a `whereIn`, not client-side.
 */
final readonly class RecentDocumentsQuery
{
    /** Rows the "Recent Documents" panel shows — matches the artboard. */
    public const LIMIT = 5;

    /**
     * @param array<int, DocumentStatus> $statuses empty = the "All" pill (no status filter)
     */
    public function __construct(
        public int $provider_id,
        public array $statuses,
        public int $limit = self::LIMIT,
    ) {
    }

    public static function fromRequest(Request $request, int $providerId): self
    {
        return new self($providerId, self::statusesForFilter($request->query('filter')));
    }

    /**
     * @return array<int, DocumentStatus>
     */
    public static function statusesForFilter(mixed $filter): array
    {
        return match (is_string($filter) ? strtolower(trim($filter)) : '') {
            'pending' => [DocumentStatus::Sent, DocumentStatus::PartiallySigned],
            'signed' => [DocumentStatus::Completed],
            'draft' => [DocumentStatus::Draft],
            default => [], // 'all', '', or anything unrecognised
        };
    }
}
