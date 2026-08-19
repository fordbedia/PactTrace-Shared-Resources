<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by PrepareEnvelopeForSignature when the document's mime type isn't
 * one DocuSign's envelope-creation API accepts — PDF only (see
 * .claude/rules/signature.md). Converting DOCX/other formats to PDF first is
 * out of scope for now; this exception is what lets both the auto-trigger in
 * DocumentController::store() and the manual "Prepare for Signature" row
 * action fail predictably instead of surfacing the provider's raw error.
 */
class UnsupportedDocumentFormatException extends RuntimeException
{
}
