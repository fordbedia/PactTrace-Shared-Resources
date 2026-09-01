<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use RuntimeException;

/**
 * A structural invariant on "change a teammate's role" / "remove a teammate"
 * was violated — distinct from a permission failure (that is a 403 from the
 * policy) and from a validation failure (a 422 from the FormRequest).
 *
 * The controller maps `reason` onto a 422 with a `{message, reason}` body so
 * the frontend renders a specific message rather than a bare 500 — same shape
 * as TeamInvitationNotAcceptableException.
 *
 *  - SELF   — the acting owner tried to change their own role or remove
 *             themselves. Blocked so a provider can never end up with zero
 *             owners through an accidental self-demotion.
 *  - OWNER  — the target user is the provider's account owner
 *             (`providers.owner_user_id`). The owner role is never reassignable
 *             and the owner is never removable through this flow; owner
 *             handoff, if it is ever built, is a separate deliberate action.
 */
final class CannotModifyTeamMemberException extends RuntimeException
{
    public const REASON_SELF = 'self';
    public const REASON_OWNER = 'owner';

    private const MESSAGES = [
        self::REASON_SELF => 'You cannot change your own role or remove yourself from the team.',
        self::REASON_OWNER => 'The provider owner cannot have their role changed or be removed here.',
    ];

    private function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'This team member cannot be modified.');
    }

    public static function actingOnSelf(): self
    {
        return new self(self::REASON_SELF);
    }

    public static function targetIsOwner(): self
    {
        return new self(self::REASON_OWNER);
    }
}
