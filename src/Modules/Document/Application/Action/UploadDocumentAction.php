<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Application\Action;

use PactTraceSDK\SharedResources\Modules\Document\Application\DTO\DocumentData;
use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Upload\DocumentUploadService;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;

/**
 * Orchestration behind the "Upload Documents" modal on /dashboard/documents
 * (see .claude/rules/document.md) — DocumentController::store() calls only
 * this. Deliberately creates a new Document row every time rather than
 * detecting "this is a new version of an existing file" — that's real
 * product logic (match on name + folder? let the user pick explicitly?)
 * that hasn't been decided yet. DocumentVersion exists in the schema for
 * when it is; this action is the natural place to add that branch later.
 */
class UploadDocumentAction
{
    public function __construct(
        private readonly DocumentUploadService $uploadService,
        private readonly DocumentRepository $documents,
    ) {
    }

    public function handle(DocumentData $data): Document
    {
        $path = $this->uploadService->store($data->file, $data->provider_id);

        return $this->documents->create([
            'provider_id' => $data->provider_id,
            'client_id' => $data->client_id,
            'matter_id' => $data->matter_id,
            'folder_id' => $data->folder_id,
            'uploaded_by' => $data->uploaded_by,
            'name' => $data->file->getClientOriginalName(),
            's3_path' => $path,
            'mime_type' => $data->file->getClientMimeType(),
            'size' => $data->file->getSize(),
            'version' => 1,
        ]);
    }
}
