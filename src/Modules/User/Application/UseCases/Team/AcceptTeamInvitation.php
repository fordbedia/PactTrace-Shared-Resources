<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserRegistration;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\SDK\Application\Ports\Transactional;
use RuntimeException;

/**
 * Use case behind "Accept Invitation" for a team member — the counterpart to
 * AcceptClientInvitation on the client side.
 *
 * Reuses UserRegistration for the login itself (email normalisation, duplicate
 * rejection, password hashing, role assignment) — none of that is
 * reimplemented here. `title` is set afterwards rather than by widening
 * register()'s signature, keeping that service unaware of invitation-only
 * fields.
 */
class AcceptTeamInvitation
{
    public function __construct(
        private readonly TeamInvitationRepository $invitations,
        private readonly UserRegistration $registration,
        private readonly Transactional $transaction,
    ) {
    }

    /**
     * @throws RuntimeException when the token is unknown, expired, or already
     *                          used — the controller maps this to a clean 4xx,
     *                          since an expired invite is an ordinary outcome.
     */
    public function handle(string $token, string $name, string $password): User
    {
        $invitation = $this->invitations->findByToken($token);

        if (! $invitation || ! $invitation->isPending()) {
            throw new RuntimeException('This invitation is invalid or has expired.');
        }

        return $this->transaction->run(function () use ($invitation, $name, $password): User {
            // $invitation->role is cast to the Role enum, and the column only
            // ever holds 'owner'/'staff' — never 'client'.
            $user = $this->registration->register(
                $name,
                $invitation->email,
                $password,
                $invitation->role,
            );

            $user->title = $invitation->title;
            $user->save();

			// Layer the specific elevated permissions directly onto that
			// one user with spatie's direct-permission API
			if ($invitation->role === 'owner') {
				$user->givePermissionTo(['user.invite', 'user.update', 'user.delete']);
			}

            $this->registration->attachToProvider($user, (int) $invitation->provider_id);

            $this->invitations->markAccepted($invitation);

            AuditLog::create([
                'provider_id' => $invitation->provider_id,
                'user_id' => $user->id,
                'action' => 'user.joined',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'metadata' => ['invited_by_user_id' => $invitation->invited_by_user_id],
            ]);

            return $user;
        });
    }
}
