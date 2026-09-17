<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Domain\Enums;

/**
 * What kind of content an archived `document_versions` row holds — see
 * .claude/rules/document.md, "Signed document storage". `documents` always
 * holds the current content (whatever it is); a `DocumentVersion` row is
 * whatever content was current immediately before it was superseded.
 * Framework-free by design (hexagonal rule in the top-level CLAUDE.md).
 */
enum DocumentVersionType: string
{
    case Original = 'original';
    case Signed = 'signed';
}
