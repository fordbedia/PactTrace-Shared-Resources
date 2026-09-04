<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\AccountDeletionSignalReader;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\DepartingStaffReassignment;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderInvitationCanceller;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\AccountDeletionBlockedException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\AccountDeletionConfirmationException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\AccountDeletionPolicy;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The `/profile` "Delete My Account" action, after the modal's confirmation.
 *
 * "Delete" is a soft deactivation (`users.status = 'deactivated'`), never a
 * hard row delete — identical reasoning to DeactivateTeamMember: cascade
 * deletes would take out documents, messages and the audit trail, and
 * `workspaces.owner_id` is `restrictOnDelete`. The design's copy ("permanently
 * deletes your personal portal access…") describes the effect from the user's
 * side; the row itself is retained for the record.
 *
 * Three gates, in order:
 *   1. No outstanding commitments (AccountDeletionPolicy) — re-checked here,
 *      not just in the modal pre-flight, so a blocker that appeared in the
 *      meantime still stops it. Only an active subscription and documents out
 *      for signature count; an unaccepted team/client invitation does not
 *      block — it is withdrawn (below) instead.
 *   2. The typed name matches the account name (case-insensitive).
 *   3. The typed password verifies against the stored hash.
 *
 * Once the gates pass, the soft-deactivation, the matter reassignment and the
 * withdrawal of every still-open team/client invitation across the provider's
 * workspaces all happen in one transaction. No email is sent to any invitee;
 * the tokens just stop resolving (same as lapsing).
 *
 * The teammate's assigned matters fall back to the owner
 * (DepartingStaffReassignment) — same as a staff removal. The controller ends
 * the session afterwards.
 */
final class DeleteOwnAccount
{
    public function __construct(
        private readonly AccountDeletionSignalReader $reader,
        private readonly UserRepository $users,
        private readonly DepartingStaffReassignment $reassignment,
        private readonly ProviderInvitationCanceller $invitationCanceller,
        private readonly Hasher $hasher,
    ) {
    }

    /**
     * @throws AccountDeletionBlockedException
     * @throws AccountDeletionConfirmationException
     */
    public function handle(User $actor, string $confirmationName, string $password): void
    {
        $providerId = (int) $actor->provider_id;
        if ($providerId > 0) {
            $blockers = AccountDeletionPolicy::blockers(
                $this->reader->read($providerId, (int) $actor->id)
            );
            if ($blockers !== []) {
                throw new AccountDeletionBlockedException($blockers);
            }
        }

        if (Str::lower(trim($confirmationName)) !== Str::lower(trim((string) $actor->name))) {
            throw new AccountDeletionConfirmationException('name');
        }

        if (! $this->hasher->check($password, (string) $actor->password)) {
            throw new AccountDeletionConfirmationException('password');
        }

        DB::transaction(function () use ($actor, $providerId): void {
            $reassignedMatters = $this->reassignment->clearMatterAssignments((int) $actor->id);

            $cancelledTeamInvites = 0;
            $cancelledClientInvites = 0;
            if ($providerId > 0) {
                $cancelledTeamInvites = $this->invitationCanceller
                    ->expirePendingTeamInvitationsForProvider($providerId);
                $cancelledClientInvites = $this->invitationCanceller
                    ->expirePendingClientInvitationsForProvider($providerId);
            }

            $this->users->deactivate($actor);

            AuditLog::create([
                'provider_id' => $actor->provider_id,
                'user_id' => $actor->id,
                'action' => 'account.self_deleted',
                'auditable_type' => User::class,
                'auditable_id' => $actor->id,
                'metadata' => [
                    'previous_role' => $actor->primaryRole()?->value,
                    'reassigned_matters' => $reassignedMatters,
                    'cancelled_team_invitations' => $cancelledTeamInvites,
                    'cancelled_client_invitations' => $cancelledClientInvites,
                ],
            ]);
        });
    }
}
