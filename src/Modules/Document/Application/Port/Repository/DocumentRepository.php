<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;

interface DocumentRepository
{
    public function create(array $data): Document;

    /**
     * One page of every document belonging to a provider — "All Documents"
     * on /dashboard/documents — optionally narrowed to one client (a
     * client-role user must never see another client's documents; see
     * TenantScopedPolicy). Newest first, uploader eager-loaded so
     * DocumentResource can show who uploaded it without an N+1.
     *
     * @return LengthAwarePaginator<int, Document>
     */
    public function forProvider(int $providerId, ?int $clientId, int $perPage, ?int $page): LengthAwarePaginator;

    /**
     * One page of the documents filed directly under any of the given folder
     * ids — ListDocumentsAction passes a folder plus every one of its
     * descendants, so focusing a parent folder shows files nested under it
     * at any depth, same as the folder tree itself.
     *
     * @param array<int, int> $folderIds
     * @return LengthAwarePaginator<int, Document>
     */
    public function forFolders(int $providerId, array $folderIds, ?int $clientId, int $perPage, ?int $page): LengthAwarePaginator;
}
