<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects;

/**
 * The one signer PactTrack's domain code models explicitly — the client a
 * document is being sent to. `clientUserId` is DocuSign's term for "the
 * stable local identifier of an embedded (captive) recipient"; it must be
 * the same value at envelope creation and at every later views/recipient
 * call for that signer, or DocuSign refuses to resolve the embedded view.
 * PrepareEnvelopeForSignature and GenerateSigningEmbedTokenUseCase both
 * derive it the same way — Client::id, cast to string — so it never needs
 * to be persisted or looked up.
 */
final class EnvelopeRecipient
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $clientUserId,
    ) {
    }
}
