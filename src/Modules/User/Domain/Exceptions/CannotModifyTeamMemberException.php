<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use RuntimeException;

/**
 * A structural invariant on "change a teammate's role" / "deactivate a
 * teammate" / "restore a teammate" was violated — distinct from a permission
 * failure (that is a 403 from the policy) and from a validation failure (a 422
 * from the FormRequest).
 *
 * The controller maps `reason` onto a 422 with a `{message, reason}` body so
 * the frontend renders a specific message rather than a bare 500 — same shape
 * as TeamInvitationNotAcceptableException.
 *
 *  - SELF        — the acting owner tried to change their own role or
 *                  deactivate/restore themselves. Blocked so a provider can
 *                  never end up with zero owners through an accidental
 *                  self-demotion.
 *  - OWNER       — the target user is the provider's account owner
 *                  (`providers.owner_user_id`). The owner role is never
 *                  reassignable and the owner is never removable through this
 *                  flow; owner handoff, if it is ever built, is a separate
 *                  deliberate action.
 *  - STAFF_ONLY  — a non-owner caller (i.e. an Admin) tried to deactivate or
 *                  restore someone who is not a Staff member. An Admin may
 *                  only change the status of Staff; the Owner and other Admins
 *                  are out of reach. Never raised for role changes — those stay
 *                  owner-only at the policy level and never reach this rule.
 */
final class CannotModifyTeamMemberException extends RuntimeException
{
    public const REASON_SELF = 'self';
    public const REASON_OWNER = 'owner';
    public const REASON_STAFF_ONLY = 'staff_only';

    private const MESSAGES = [
        self::REASON_SELF => 'You cannot change your own role or remove yourself from the team.',
        self::REASON_OWNER => 'The provider owner cannot have their role changed or be removed here.',
        self::REASON_STAFF_ONLY => 'Admins can only deactivate or restore Staff members.',
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

    public static function targetNotStaff(): self
    {
        return new self(self::REASON_STAFF_ONLY);
    }
}
