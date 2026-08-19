<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Application\UseCases;

use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Exceptions\DocumentCannotBeVoidedException;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTraceSDK\SharedResources\Modules\User\Models\User;

/**
 * The cancellation path for an in-flight signature request (sent or
 * partially_signed) — the alternative to deletion, which
 * DocumentDeletionPolicy refuses once a document has left draft. Voiding
 * preserves the record and its audit trail entry. See
 * .claude/rules/document.md, "Document Deletion & Archival Rules".
 */
class VoidDocumentHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {
    }

    public function handle(Document $document, User $actor): Document
    {
        if (! $document->status->isVoidable()) {
            throw DocumentCannotBeVoidedException::forStatus($document->status);
        }

        $previousStatus = $document->status;

        $document = $this->documents->save($document, ['status' => DocumentStatus::Voided]);

        AuditLog::create([
            'provider_id' => $document->provider_id,
            'user_id' => $actor->id,
            'action' => 'document.voided',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
            'metadata' => [
                'previous_status' => $previousStatus->value,
            ],
        ]);

        return $document;
    }
}
