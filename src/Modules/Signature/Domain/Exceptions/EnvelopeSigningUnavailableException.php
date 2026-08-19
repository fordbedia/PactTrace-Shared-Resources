<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the configured ESignatureProvider cannot mint an embedded
 * signing view — either the provider is unreachable, or (the known case for
 * DocuSign: the impersonated user hasn't completed the one-time JWT Grant
 * consent flow yet) the provider rejects the call outright. Callers must
 * translate this into a clear, non-500 response rather than letting it
 * crash the request — see SigningController::signingToken().
 */
class EnvelopeSigningUnavailableException extends RuntimeException
{
    public static function fromProviderFailure(Throwable $previous): self
    {
        return new self(
            'Embedded signing is not available right now. This can happen if the '
            . 'configured e-signature provider is unreachable, or (for DocuSign) if '
            . 'one-time consent has not yet been granted — see .claude/rules/signature.md.',
            previous: $previous,
        );
    }
}
