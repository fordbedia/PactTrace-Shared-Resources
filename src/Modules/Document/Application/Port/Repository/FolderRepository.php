<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository;

use Illuminate\Support\Collection;
use PactTraceSDK\SharedResources\Modules\Document\Models\Folder;

interface FolderRepository
{
    public function create(array $data): Folder;

    /**
     * Every folder belonging to a provider, flat (not yet built into a
     * tree) — ListFolderTree is what turns this into the nested shape the
     * frontend renders.
     *
     * @return Collection<int, Folder>
     */
    public function allForProvider(int $providerId): Collection;
}
