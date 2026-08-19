<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Application\UseCases;

use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientInvitationRepository;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Client\Models\ClientInvitation;
use PactTrackSDK\SharedResources\SDK\Application\Ports\Transactional;

/**
 * Use case behind "Invite Client": creates/updates the CRM record and issues
 * the token the client will need to claim it.
 *
 * Previously the client row and the invitation email existed but nothing
 * ever wrote a `client_invitations` row — the email's "Accept Invitation"
 * button pointed at a generic sign-in page with no way for a brand-new
 * client to get an account. This is the missing half of that flow; see
 * AcceptClientInvitation for the other half.
 */
class InviteClient
{
    /** How long an invitation link stays valid before the client has to be re-invited. */
    private const EXPIRES_IN_DAYS = 7;

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ClientInvitationRepository $invitations,
        private readonly Transactional $transaction,
    ) {
    }

    /**
     * @return array{0: Client, 1: ClientInvitation}
     */
    public function handle(ClientData $data, int $invitedByUserId): array
    {
        return $this->transaction->run(function () use ($data, $invitedByUserId) {
            $client = $this->clients->upsert($data);

            // A re-invite should not leave an older, still-valid link
            // outstanding alongside the new one.
            $this->invitations->invalidatePendingForClient((int) $client->getKey());

            $invitation = $this->invitations->create([
                'provider_id' => $data->provider_id,
                'client_id' => $client->getKey(),
                'invited_by' => $invitedByUserId,
                'email' => $data->email,
                'token' => Str::random(48),
                'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
            ]);

            return [$client, $invitation];
        });
    }
}
