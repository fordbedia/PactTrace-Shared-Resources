<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Application\UseCases;

use PactTraceSDK\SharedResources\Modules\Document\Application\DTO\FolderData;
use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository\FolderRepository;
use PactTraceSDK\SharedResources\Modules\Document\Models\Folder;

/**
 * Use case behind the "+" (new folder) action on /dashboard/documents —
 * see .claude/rules/document.md. Mirrors UploadDocumentAction's shape: thin,
 * framework-adjacent orchestration only, persistence through the
 * FolderRepository port.
 */
class CreateFolder
{
    public function __construct(
        private readonly FolderRepository $folders,
    ) {
    }

    public function handle(FolderData $data): Folder
    {
        return $this->folders->create([
            'provider_id' => $data->provider_id,
            'client_id' => $data->client_id,
            'matter_id' => $data->matter_id,
            'parent_id' => $data->parent_id,
            'name' => $data->name,
        ]);
    }
}
