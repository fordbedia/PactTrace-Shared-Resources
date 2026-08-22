<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects;

/**
 * One recipient on an envelope. `clientUserId` is DocuSign's term for "the
 * stable local identifier of an embedded (captive) recipient"; it must be
 * the same value at envelope creation and at every later views/recipient
 * call for that signer, or DocuSign refuses to resolve the embedded view.
 * Every recipient PactTrack creates today is embedded — there is no
 * PactTrack-constructed remote/DocuSign-hosted-email recipient anymore:
 *
 * - The document's own client: PrepareEnvelopeForSignature and
 *   GenerateSigningEmbedTokenUseCase both derive it the same way —
 *   Client::id, cast to string — so it never needs to be persisted.
 * - An ad-hoc co-signer (a "guest" — no PactTrack Client/User record):
 *   PrepareEnvelopeForSignature and GenerateGuestSigningEmbedTokenUseCase
 *   both derive it from the co-signer's own email instead, since that's
 *   the only value known before their Signer row exists and stable
 *   afterward. They sign through PactTrack's own tokenized guest link, not
 *   a DocuSign-hosted email — see .claude/rules/signature.md, "Guest
 *   signers".
 *
 * `clientUserId: null` still marks an ordinary DocuSign "remote" recipient
 * as far as the port/provider are concerned, but nothing in this codebase
 * constructs one that way today.
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
