<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums;

/**
 * An envelope's position in the send/sign lifecycle. Framework-free by
 * design (hexagonal rule in the top-level CLAUDE.md).
 *
 * draft -> sent -> viewed -> partially_signed -> completed is the happy
 * path; declined/voided/expired are terminal off-ramps reachable from any
 * non-terminal state. Mirrors documents.status (see
 * .claude/rules/document.md) one level more granular, since a single
 * Document can only be draft/sent/partially_signed/completed/voided while
 * its Envelope also tracks viewed/declined/expired.
 */
enum EnvelopeStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case PartiallySigned = 'partially_signed';
    case Completed = 'completed';
    case Declined = 'declined';
    case Voided = 'voided';
    case Expired = 'expired';

    /**
     * A terminal envelope never transitions again — see
     * Envelope::assertNotTerminal(), called by every mark*() method.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Declined, self::Voided, self::Expired => true,
            default => false,
        };
    }
}
