<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by PrepareEnvelopeForSignature::handle() when a local `draft`
 * Envelope row is no longer actually a draft on the provider's side (e.g.
 * the tenant already sent it, but the DocuSign Connect webhook that would
 * flip our local `status` to `sent` never landed — see
 * .claude/rules/signature.md, "Webhooks"). Requesting a fresh Sender View
 * for an envelope DocuSign no longer considers editable makes DocuSign
 * bounce the iframe immediately instead of showing its editor, which is
 * what this guards against.
 */
class EnvelopeAlreadySentException extends RuntimeException
{
    public static function forProviderStatus(string $providerStatus): self
    {
        return new self(
            "This document has already been sent for signature (provider status: [{$providerStatus}])."
            . ' Refresh the page to see its current status.'
        );
    }
}
