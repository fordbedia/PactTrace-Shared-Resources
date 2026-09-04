<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * A reason PactTrack will not let a user delete their own account yet.
 *
 * The `/profile` "Delete Account" flow runs a pre-flight check before it ever
 * asks for the name/password confirmation — if the acting user's provider has
 * any of these outstanding, the modal shows the blocker(s) and the
 * confirmation form is never rendered. See `AccountDeletionPolicy`.
 *
 * Framework-free by design (hexagonal rule in the top-level CLAUDE.md) — this
 * is domain vocabulary, not an Eloquent concern.
 */
enum AccountDeletionBlocker: string
{
    case ActiveSubscription = 'active_subscription';
    case PendingDocuments = 'pending_documents';

    /*
     * Unaccepted team/client invitations are deliberately NOT blockers. A
     * pending invitation nobody has accepted establishes no relationship and
     * carries nothing worth protecting, so deleting the account quietly
     * expires every pending invitation across all of the provider's
     * workspaces (see `DeleteOwnAccount` / `ProviderInvitationCanceller`)
     * rather than refusing until they are revoked by hand.
     */

    /** Short human label for the blocker list. */
    public function label(): string
    {
        return match ($this) {
            self::ActiveSubscription => 'Active subscription',
            self::PendingDocuments => 'Documents awaiting signature',
        };
    }

    /** What the user has to do to clear it. */
    public function resolution(): string
    {
        return match ($this) {
            self::ActiveSubscription => 'Cancel your current plan from Billing before deleting your account.',
            self::PendingDocuments => 'Complete or void every document that is out for signature.',
        };
    }
}
