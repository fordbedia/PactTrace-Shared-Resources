<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\Action;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentListData;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\FolderRepository;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Orchestration behind the document table on /dashboard/documents (see
 * .claude/rules/document.md) — DocumentController::index() calls only
 * this. Focusing a folder must show its own documents *and* every
 * document nested under it at any depth (same rule the folder tree itself
 * already follows client-side via collectDescendantIds), so a folder id
 * expands to that folder plus every descendant before the document query
 * runs, rather than an exact `folder_id` match.
 *
 * `matter_id` alone (no folder scope, no other filter) still routes to the
 * legacy, folder-agnostic `forMatter()` path — it backs the "Documents on
 * this matter" section of the Matter Detail view on /dashboard/matters (see
 * .claude/rules/matter.md), which has no folder UI at all and is left
 * completely untouched by the Matter/Client/File Type/Date Range filter
 * chips below. That page's own request never carries a `folder_id` or any
 * of the new filter params, so this heuristic reproduces its exact prior
 * behaviour byte-for-byte. The moment a folder is focused (or any other
 * filter chip is active) alongside a Matter filter — the
 * /dashboard/documents toolbar's own case — `matter_id` instead becomes one
 * more narrowing condition on the folder-scoped path, via `$data->filters`.
 * See DocumentFilters and .claude/rules/document.md, "Matter and Client
 * filters".
 *
 * `client_id` backs the Client Detail page's Documents tab
 * (/dashboard/clients, see .claude/rules/client.md) and the Documents page's
 * own Client filter chip — but only for a provider-side caller. A
 * client-portal user's own `client_id` (derived below from the acting user,
 * never from the request) always wins instead: the request field simply
 * doesn't apply to them, the same "derive from the resolved actor, don't
 * trust the request for it" rule the rest of this module already applies
 * elsewhere.
 *
 * Returns a LengthAwarePaginator, not a Collection: a provider's library
 * grows without bound, so the table pages server-side — same shape as
 * ListMattersHandler (see .claude/rules/matter.md). Note the folder tree
 * itself is deliberately *not* paginated — that's one query for the whole
 * tree (ListFolderTree), and the expansion below needs all of it to resolve
 * descendants regardless of which document page is being asked for.
 */
class ListDocumentsAction
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly FolderRepository $folders,
    ) {
    }

    /**
     * @return LengthAwarePaginator<int, Document>
     */
    public function handle(User $user, DocumentListData $data): LengthAwarePaginator
    {
        $providerId = (int) $user->provider_id;
        $clientId = $user->isClientUser() ? $user->client?->id : $data->client_id;
        $filters = $data->filters->withClientId($clientId);

        $onlyMatterIdSet = $data->matter_id !== null
            && $data->folder_id === null
            && $filters->fileTypes === []
            && $filters->dateFrom === null
            && $filters->dateTo === null
            && ($filters->search === null || $filters->search === '');

        if ($onlyMatterIdSet) {
            return $this->documents->forMatter($providerId, $data->matter_id, $clientId, $data->per_page, $data->page, $data->archived);
        }

        if ($data->folder_id === null) {
            return $this->documents->forProvider($providerId, $filters, $data->per_page, $data->page, $data->archived);
        }

        $folderIds = $this->folderAndDescendantIds($data->folder_id, $this->folders->allForProvider($providerId));

        return $this->documents->forFolders($providerId, $folderIds, $filters, $data->per_page, $data->page, $data->archived);
    }

    /**
     * @param Collection<int, \PactTrackSDK\SharedResources\Modules\Document\Models\Folder> $allFolders
     * @return array<int, int>
     */
    private function folderAndDescendantIds(int $folderId, Collection $allFolders): array
    {
        $ids = [$folderId];

        foreach ($allFolders->where('parent_id', $folderId) as $child) {
            $ids = [...$ids, ...$this->folderAndDescendantIds((int) $child->id, $allFolders)];
        }

        return $ids;
    }
}
