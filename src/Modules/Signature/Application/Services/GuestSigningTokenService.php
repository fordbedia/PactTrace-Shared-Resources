<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\Services;

use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\GuestSigningTokenUnavailableException;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;

/**
 * Issues and verifies guest (no PactTrack account) signing tokens — see
 * .claude/rules/signature.md, "Guest signers". A small, single-purpose
 * service per BUILD_PLAN.md's Engineering Principles, kept out of
 * RecordSignatureCompletionUseCase/GuestSigningController so both only ever
 * call it rather than reimplementing token handling.
 *
 * Tokens are hashed with sha256 rather than bcrypt (Laravel's password-reset
 * broker's choice): bcrypt's per-hash random salt makes an equality lookup
 * by hash impossible without first knowing which row to check against, and
 * there's no email/identity to key that lookup on here the way the
 * password-reset broker has. sha256 is deterministic, so
 * `where('signing_token_hash', hash('sha256', $token))` finds the right
 * Signer directly — the same approach Sanctum's PersonalAccessToken uses for
 * this exact "long-lived bearer token, verified by DB equality" shape.
 */
class GuestSigningTokenService
{
    private const RANDOM_LENGTH = 40;

    private const EXPIRY_DAYS = 14;

    /**
     * Generates a fresh token, persists only its hash + expiry on the given
     * Signer, and returns the raw token for the caller to embed in an email
     * immediately — the raw value is never itself persisted. Called once,
     * at send time (RecordSignatureCompletionUseCase::notifyCoSigners()),
     * not when the Signer row is first created, since the raw token must
     * never be stored to be re-read later.
     */
    public function issueFor(Signer $signer): string
    {
        $token = $this->generateRawToken();

        $signer->signing_token_hash = $this->hash($token);
        $signer->signing_token_expires_at = now()->addDays(self::EXPIRY_DAYS);
        $signer->save();

        return $token;
    }

    /**
     * Raw token shape: `{unix_ms_timestamp}.{uuid4}.{random_40}`. `Str::uuid()`
     * (UUIDv4) is idiomatic Laravel and already this codebase's only other
     * public-facing-identifier convention besides ULID (`Envelope::public_id`
     * — a ULID wasn't reused here on purpose: a ULID's whole point is being
     * sortable/comparable in the clear, which is the opposite of what a
     * bearer credential wants). The leading millisecond timestamp is a
     * collision-resistance floor on top of that, not a substitute for
     * randomness — two tokens issued in the same process tick can never
     * collide even in the (already astronomically unlikely) event of a
     * `Str::uuid()` collision. `Str::random(40)` — the same length this
     * service always generated — remains the actual unpredictability
     * guarantee: it draws from `random_bytes()`, so nothing here narrows the
     * space DocuSign-style token guessing would have to search. Only the
     * sha256 hash of the full composed string is ever persisted (see
     * `issueFor()`); the raw value lives only for the one request that mints
     * it.
     */
    private function generateRawToken(): string
    {
        return sprintf(
            '%d.%s.%s',
            (int) round(microtime(true) * 1000),
            (string) Str::uuid(),
            Str::random(self::RANDOM_LENGTH),
        );
    }

    /**
     * Resolves a raw token to the Signer it was issued for, scoped to the
     * given Envelope so a token can never be replayed against a different
     * envelope's route. Throws GuestSigningTokenUnavailableException with a
     * reason the caller can map to the right HTTP status / frontend copy.
     */
    public function resolve(Envelope $envelope, string $rawToken): Signer
    {
        $signer = $envelope->signers()
            ->where('signing_token_hash', $this->hash($rawToken))
            ->first();

        if ($signer === null) {
            throw GuestSigningTokenUnavailableException::invalid();
        }

        if ($signer->signing_token_consumed_at !== null) {
            throw GuestSigningTokenUnavailableException::consumed();
        }

        if ($signer->signing_token_expires_at !== null && $signer->signing_token_expires_at->isPast()) {
            throw GuestSigningTokenUnavailableException::expired();
        }

        return $signer;
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
