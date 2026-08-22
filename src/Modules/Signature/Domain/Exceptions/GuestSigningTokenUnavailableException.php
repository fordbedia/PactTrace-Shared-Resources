<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by GuestSigningTokenService::resolve() when a guest signing link
 * (`?signingLinkToken=...&envelope=...`) can't be honored. `reason` lets the
 * controller/frontend distinguish "never valid" from "was valid, isn't
 * anymore" without three separate exception classes — see
 * .claude/rules/signature.md, "Guest signers".
 */
class GuestSigningTokenUnavailableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    /** No signer under the given envelope has a matching token hash. */
    public static function invalid(): self
    {
        return new self('This signing link is not valid.', 'invalid');
    }

    /** Matched, but signing_token_expires_at has passed. */
    public static function expired(): self
    {
        return new self('This signing link has expired.', 'expired');
    }

    /** Matched, but this signer already completed signing. */
    public static function consumed(): self
    {
        return new self('This document has already been signed.', 'consumed');
    }
}
