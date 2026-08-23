<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use RuntimeException;

/**
 * Thrown by GenerateSigningEmbedTokenUseCase when an envelope isn't in a
 * state a signing token can be minted for — either it's terminal (already
 * completed/declined/voided/expired, so there's nothing left to sign) or it
 * was never sent to the provider at all (still `draft`, no
 * provider_envelope_id yet).
 */
class EnvelopeNotSignableException extends RuntimeException
{
    public static function terminal(EnvelopeStatus $status): self
    {
        return new self("Envelope is {$status->value} and can no longer be signed.");
    }

    public static function notYetSent(): self
    {
        return new self('Envelope has not been sent to the e-signature provider yet.');
    }
}
