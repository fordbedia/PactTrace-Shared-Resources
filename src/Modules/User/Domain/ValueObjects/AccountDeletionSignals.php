<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * A snapshot of the things `AccountDeletionPolicy` weighs before letting a
 * user delete their own account — read for the acting user's provider by the
 * `AccountDeletionSignalReader` port.
 *
 * Framework-free: plain scalars, no Eloquent. The Infrastructure adapter does
 * the counting; the policy does the deciding.
 */
final readonly class AccountDeletionSignals
{
    public function __construct(
        /** The provider's Subscription.status, or null if it has no row. */
        public ?string $subscriptionStatus,
        /** Documents still out for signature (sent / partially_signed). */
        public int $pendingDocumentCount,
        /** team_invitations rows not yet accepted. */
        public int $pendingTeamInvitationCount,
        /** client_invitations rows not yet accepted. */
        public int $pendingClientInvitationCount,
    ) {
    }
}
