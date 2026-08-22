<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * Maps `Document.status` (draft/sent/partially_signed/completed/voided —
 * see .claude/rules/document.md, "Document Deletion & Archival Rules") onto
 * the pill the client portal renders for it. Server-side for the same
 * reason `MatterCountFormatter`/`ByteFormatter` are: the wording stays
 * identical everywhere it's shown, and the frontend never has to know the
 * enum's raw values.
 *
 * `Draft` is included for completeness (every DocumentStatus case must map
 * to *something*) even though PortalMatterController filters draft
 * documents out of the list it sends the client — a draft hasn't been sent
 * to them yet, so a client never sees this pill in practice today. See
 * that controller's own note on this being a flagged, not silently decided,
 * assumption.
 */
final class PortalDocumentStatusMapper
{
    /**
     * @return array{key: string, label: string}
     */
    public static function map(DocumentStatus $status): array
    {
        return match ($status) {
            DocumentStatus::Draft => ['key' => 'draft', 'label' => 'Draft'],
            DocumentStatus::Sent => ['key' => 'awaiting', 'label' => 'Awaiting signature'],
            DocumentStatus::PartiallySigned => ['key' => 'partially_signed', 'label' => 'Partially signed'],
            DocumentStatus::Completed => ['key' => 'signed', 'label' => 'Signed'],
            DocumentStatus::Voided => ['key' => 'voided', 'label' => 'Voided'],
        };
    }
}
