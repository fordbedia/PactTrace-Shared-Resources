<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\UseCases;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The Documents page's bulk "Archive" action (the bulk bar's Delete button
 * was renamed to Archive — see .claude/rules/document.md, "Document
 * Deletion & Archival Rules": Archive is the sole user-facing removal
 * action, there is no bulk delete). Deliberately just a loop over the same
 * per-document {@see ArchiveDocumentHandler} the single-row "Archive" row
 * action already uses, rather than a second archiving implementation.
 */
class BulkArchiveDocumentsHandler
{
    public function __construct(private readonly ArchiveDocumentHandler $archiveDocument)
    {
    }

    /**
     * @param Collection<int, Document> $documents
     */
    public function handle(Collection $documents, User $actor): Collection
    {
        return $documents->map(fn (Document $document): Document => $this->archiveDocument->handle($document, $actor));
    }
}
