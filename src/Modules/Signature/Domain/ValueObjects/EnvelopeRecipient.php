<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects;

/**
 * One recipient on an envelope. `clientUserId` is DocuSign's term for "the
 * stable local identifier of an embedded (captive) recipient"; it must be
 * the same value at envelope creation and at every later views/recipient
 * call for that signer, or DocuSign refuses to resolve the embedded view.
 * PrepareEnvelopeForSignature and GenerateSigningEmbedTokenUseCase both
 * derive it the same way for the document's own client — Client::id, cast
 * to string — so it never needs to be persisted or looked up.
 *
 * `clientUserId: null` marks an ordinary DocuSign "remote" signer instead —
 * an ad-hoc co-signer with no PactTrack Client/User record, who signs via
 * DocuSign's own emailed link rather than embedded in PactTrack's portal.
 * See .claude/rules/signature.md.
 */
final class EnvelopeRecipient
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $clientUserId,
    ) {
    }
}
