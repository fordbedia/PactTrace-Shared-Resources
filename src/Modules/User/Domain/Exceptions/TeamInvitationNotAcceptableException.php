<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a team-invitation token can't be redeemed — and *why*.
 *
 * The accept flow deliberately tells "we've never seen this link" apart from
 * "this link is used up", and within the latter, "expired" apart from "already
 * accepted", so the accept-invitation page can render its own distinct state
 * instead of a generic error. TeamInvitationController maps `reason` to an HTTP
 * status via {@see self::httpStatus()} and echoes `reason` in the body.
 *
 * Extends RuntimeException so the existing use-case tests
 * (`expectException(RuntimeException::class)`) keep passing.
 */
final class TeamInvitationNotAcceptableException extends RuntimeException
{
    public const REASON_UNKNOWN = 'unknown';
    public const REASON_EXPIRED = 'expired';
    public const REASON_ACCEPTED = 'accepted';

    /** @var array<string, string> */
    private const MESSAGES = [
        self::REASON_UNKNOWN => 'This invitation link is invalid.',
        self::REASON_EXPIRED => 'This invitation has expired. Ask an admin to send you a new one.',
        self::REASON_ACCEPTED => 'This invitation has already been used. Try signing in instead.',
    ];

    private function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES[self::REASON_UNKNOWN]);
    }

    public static function unknown(): self
    {
        return new self(self::REASON_UNKNOWN);
    }

    /**
     * @param  string  $reason  one of TeamInvitation::unusableReason()'s values
     *                          ('expired' | 'accepted').
     */
    public static function forReason(string $reason): self
    {
        return new self($reason);
    }

    /** 404 for a link that resolves to nothing, 410 for one that's simply spent. */
    public function httpStatus(): int
    {
        return $this->reason === self::REASON_UNKNOWN ? 404 : 410;
    }
}
