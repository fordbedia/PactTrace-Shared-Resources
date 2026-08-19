<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The inverse of ArchiveDocumentHandler — clears `archived_at`. Same "any
 * status" rule applies: unarchiving is not a status transition. See
 * .claude/rules/document.md, "Document Deletion & Archival Rules".
 */
class UnarchiveDocumentHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {
    }

    public function handle(Document $document, User $actor): Document
    {
        $previousStatus = $document->status;

        $document = $this->documents->save($document, ['archived_at' => null]);

        AuditLog::create([
            'provider_id' => $document->provider_id,
            'user_id' => $actor->id,
            'action' => 'document.unarchived',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
            'metadata' => [
                'previous_status' => $previousStatus->value,
            ],
        ]);

        return $document;
    }
}
