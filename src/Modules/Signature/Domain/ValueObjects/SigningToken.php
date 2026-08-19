<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * A short-lived embed URL for one recipient to sign one envelope inside an
 * embedded iframe — see .claude/rules/signature.md, "Flow B". Never
 * persisted; minted on demand by GenerateSigningEmbedTokenUseCase and handed
 * straight to the frontend.
 *
 * DocuSign's recipient view has no separate reusable "token" distinct from
 * the URL itself (the URL is single-use, ~5-minute-lived) — `token` is kept
 * only for response-shape stability with callers that already expect this
 * field (`SigningController::signingToken()`'s JSON, which the frontend
 * doesn't actually read), carrying the recipientId DocuSign resolved the
 * view against.
 *
 * `providerSignerId` rides along so the use case that requested this token
 * can backfill Signer::provider_signer_id without a second port call.
 */
final class SigningToken
{
    public function __construct(
        public readonly string $token,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $signingUrl,
        public readonly string $providerSignerId,
    ) {
    }
}
