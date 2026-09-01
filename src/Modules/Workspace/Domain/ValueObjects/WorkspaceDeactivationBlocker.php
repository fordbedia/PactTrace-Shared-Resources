<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects;

/**
 * A reason PactTrack will not let a provider deactivate one of its workspaces
 * yet.
 *
 * The Account Settings "Deactivate Workspace" flow runs a pre-flight check
 * before it ever asks for the name/password confirmation — if the chosen
 * workspace still has live activity of any of these kinds, the modal lists the
 * blocker(s) and the confirmation form is never shown. See
 * `WorkspaceDeactivationPolicy`.
 *
 * Deliberately parallels the User module's `AccountDeletionBlocker`: same
 * shape, same `label()`/`resolution()` contract, so the two "resolve your
 * outstanding activity first" modals behave identically. Framework-free per the
 * hexagonal rule in the top-level CLAUDE.md.
 */
enum WorkspaceDeactivationBlocker: string
{
    case OpenMatters = 'open_matters';
    case PendingDocuments = 'pending_documents';
    case PendingEnvelopes = 'pending_envelopes';
    case PendingClientInvitations = 'pending_client_invitations';

    /** Short human label for the blocker list. */
    public function label(): string
    {
        return match ($this) {
            self::OpenMatters => 'Matters still open',
            self::PendingDocuments => 'Documents awaiting signature',
            self::PendingEnvelopes => 'Signature requests in progress',
            self::PendingClientInvitations => 'Unaccepted client invitations',
        };
    }

    /** What the provider has to do to clear it. */
    public function resolution(): string
    {
        return match ($this) {
            self::OpenMatters => 'Complete or cancel every matter in this workspace first.',
            self::PendingDocuments => 'Complete or void every document that is out for signature.',
            self::PendingEnvelopes => 'Finish or void every signature request that has not reached a final state.',
            self::PendingClientInvitations => 'Revoke or wait for every pending client invitation tied to this workspace.',
        };
    }
}
