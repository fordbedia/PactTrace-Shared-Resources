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
    /**
     * The provider's primary workspace — the one RegisterProvider stamps at
     * sign-up. Unlike every other case here, this is not a live-activity
     * signal: it is an intrinsic property of the workspace, checked before the
     * activity signals are even read, and can never be "resolved" the way the
     * others can. See DeactivateWorkspace / WorkspaceController.
     */
    case IsPrimaryWorkspace = 'is_primary_workspace';

    case OpenMatters = 'open_matters';
    case PendingDocuments = 'pending_documents';
    case PendingEnvelopes = 'pending_envelopes';

    /*
     * Unaccepted client/team invitations are deliberately NOT blockers. A
     * pending invitation nobody has accepted establishes no relationship and
     * carries nothing worth protecting, so deactivating a workspace quietly
     * expires any client invitation scoped to it (see
     * `DeactivateWorkspace` / `WorkspaceInvitationCanceller`) rather than
     * refusing until the provider revokes it by hand. Blockers here are
     * reserved for work or a legal document already in flight.
     */

    /** Short human label for the blocker list. */
    public function label(): string
    {
        return match ($this) {
            self::IsPrimaryWorkspace => 'This is your primary workspace',
            self::OpenMatters => 'Matters still open',
            self::PendingDocuments => 'Documents awaiting signature',
            self::PendingEnvelopes => 'Signature requests in progress',
        };
    }

    /** What the provider has to do to clear it. */
    public function resolution(): string
    {
        return match ($this) {
            self::IsPrimaryWorkspace => "Primary workspaces can't be deactivated. If you want to reorganize your practice, create additional workspaces instead.",
            self::OpenMatters => 'Complete or cancel every matter in this workspace first.',
            self::PendingDocuments => 'Complete or void every document that is out for signature.',
            self::PendingEnvelopes => 'Finish or void every signature request that has not reached a final state.',
        };
    }
}
