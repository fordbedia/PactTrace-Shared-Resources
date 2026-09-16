<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\UseCases;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The Documents page's bulk "Move" action — reassigns `folder_id` for every
 * selected document to one target folder (or unfiled, when
 * `$targetFolderId` is null). Every document here has already been
 * individually authorized (`update`) and tenant/folder-ownership checked by
 * the controller, same division of responsibility as
 * ArchiveDocumentHandler/UnarchiveDocumentHandler.
 */
class MoveDocumentsHandler
{
    public function __construct(private readonly DocumentRepository $documents)
    {
    }

    /**
     * @param Collection<int, Document> $documents
     */
    public function handle(Collection $documents, ?int $targetFolderId, User $actor): Collection
    {
        return $documents->map(function (Document $document) use ($targetFolderId, $actor): Document {
            $previousFolderId = $document->folder_id;

            $document = $this->documents->save($document, ['folder_id' => $targetFolderId]);

            AuditLog::create([
                'provider_id' => $document->provider_id,
                'user_id' => $actor->id,
                'action' => 'document.moved',
                'auditable_type' => Document::class,
                'auditable_id' => $document->id,
                'metadata' => [
                    'previous_folder_id' => $previousFolderId,
                    'new_folder_id' => $targetFolderId,
                ],
            ]);

            return $document;
        });
    }
}
