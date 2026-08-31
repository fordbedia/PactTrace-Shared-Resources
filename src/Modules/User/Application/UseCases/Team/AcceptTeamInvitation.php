<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserRegistration;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\TeamInvitationNotAcceptableException;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\SDK\Application\Ports\Transactional;

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
     * @throws TeamInvitationNotAcceptableException  when the token is unknown,
     *         expired, or already used — carries a `reason` the controller maps
     *         to a 404/410 + body, since a dead invite is an ordinary outcome,
     *         not a bug. (Extends RuntimeException.)
     */
    public function handle(string $token, string $name, string $password): User
    {
        $invitation = $this->invitations->findByToken($token);

        if ($invitation === null) {
            throw TeamInvitationNotAcceptableException::unknown();
        }

        if (($reason = $invitation->unusableReason()) !== null) {
            throw TeamInvitationNotAcceptableException::forReason($reason);
        }

        return $this->transaction->run(function () use ($invitation, $name, $password): User {
            // $invitation->role is a Role enum and the invite column only ever
            // holds 'admin' or 'staff' (never 'owner'/'client' — see
            // TeamInviteFormRequest / the team_invitations migration). Each
            // carries its own permission set via Role::permissions(), applied
            // by UserRegistration::register()'s assignRole() — "Admin" is a
            // first-class role now, not staff-plus-layered-permissions.
            $user = $this->registration->register(
                $name,
                $invitation->email,
                $password,
                $invitation->role,
            );

            $user->title = $invitation->title;
            $user->save();

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
