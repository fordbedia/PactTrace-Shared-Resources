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
        $clientId = $user->isClientUser() ? $user->client?->id : null;

        if ($data->folder_id === null) {
            return $this->documents->forProvider($providerId, $clientId, $data->per_page, $data->page, $data->archived);
        }

        $folderIds = $this->folderAndDescendantIds($data->folder_id, $this->folders->allForProvider($providerId));

        return $this->documents->forFolders($providerId, $folderIds, $clientId, $data->per_page, $data->page, $data->archived);
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
