<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Application\UseCases;

use Illuminate\Support\Collection;
use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository\FolderRepository;
use PactTraceSDK\SharedResources\Modules\Document\Models\Folder;

/**
 * Turns a provider's flat `folders` rows into the nested `{ id, name,
 * children: [] }` shape /dashboard/documents' FolderTreeItem already
 * renders recursively (see .claude/rules/document.md) — one query, grouped
 * in memory, so an arbitrarily deep tree costs the same single round trip
 * a flat list would.
 */
class ListFolderTree
{
    public function __construct(
        private readonly FolderRepository $folders,
    ) {
    }

    public function handle(int $providerId): array
    {
        return $this->buildTree($this->folders->allForProvider($providerId), null);
    }

    /**
     * @param Collection<int, Folder> $folders
     */
    private function buildTree(Collection $folders, ?int $parentId): array
    {
        return $folders
            ->where('parent_id', $parentId)
            ->values()
            ->map(fn (Folder $folder) => [
                'id' => $folder->id,
                'name' => $folder->name,
                'parent_id' => $folder->parent_id,
                'client_id' => $folder->client_id,
                'matter_id' => $folder->matter_id,
                'children' => $this->buildTree($folders, $folder->id),
            ])
            ->all();
    }
}
