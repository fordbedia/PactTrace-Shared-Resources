<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Domain\Services;

use PactTraceSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * Whether a document may be permanently (soft-)deleted at all — see
 * .claude/rules/document.md, "Document Deletion & Archival Rules".
 *
 * A hard rule, not a UX nicety: once a document has left `draft` it is part
 * of the signature audit trail and must never be deletable again, not even
 * by an owner/admin. In-flight documents are cancelled via Void instead
 * (DocumentStatus::isVoidable()), which preserves the record.
 *
 * Deliberately its own class rather than inline logic in
 * DeleteDocumentHandler, so the rule has exactly one place it can be
 * expressed and every caller goes through it.
 */
final class DocumentDeletionPolicy
{
    public function canBeDeleted(DocumentStatus $status): bool
    {
        return $status === DocumentStatus::Draft;
    }
}
