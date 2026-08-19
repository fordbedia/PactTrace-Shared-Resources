<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Exceptions\DocumentCannotBeDeletedException;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Services\DocumentDeletionPolicy;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The only path allowed to soft-delete a Document — see
 * .claude/rules/document.md, "Document Deletion & Archival Rules".
 *
 * The status check is delegated to DocumentDeletionPolicy rather than
 * reimplemented here, so the "only draft is deletable" rule has one place
 * it can be expressed. An in-flight document (sent/partially_signed) must
 * be cancelled with VoidDocumentHandler instead.
 */
class DeleteDocumentHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentDeletionPolicy $deletionPolicy,
    ) {
    }

    public function handle(Document $document, User $actor): void
    {
        if (! $this->deletionPolicy->canBeDeleted($document->status)) {
            throw DocumentCannotBeDeletedException::forStatus($document->status);
        }

        $previousStatus = $document->status;

        $this->documents->delete($document);

        AuditLog::create([
            'provider_id' => $document->provider_id,
            'user_id' => $actor->id,
            'action' => 'document.deleted',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
            'metadata' => [
                'previous_status' => $previousStatus->value,
            ],
        ]);
    }
}
