<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Application\Action;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Dashboard\Application\DTO\RecentDocumentsQuery;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;

/**
 * The "Recent Documents" list on `/dashboard` — the tenant's most-recently
 * updated non-archived documents, optionally narrowed to the pill's
 * lifecycle states (folding done in RecentDocumentsQuery, not here).
 *
 * Thin over the Document module's repository port — the query and its
 * eager-loads live in the Eloquent adapter, this action only threads the
 * DTO through.
 *
 * @see RecentDocumentsQuery
 */
final class GetRecentDocumentsAction
{
    public function __construct(private readonly DocumentRepository $documents)
    {
    }

    /**
     * @return Collection<int, \PactTrackSDK\SharedResources\Modules\Document\Models\Document>
     */
    public function handle(RecentDocumentsQuery $query): Collection
    {
        return $this->documents->recentForProvider($query->provider_id, $query->statuses, $query->limit);
    }
}
