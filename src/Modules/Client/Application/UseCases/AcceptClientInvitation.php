<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Application\UseCases;

use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientInvitationRepository;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\User\Application\Services\UserRegistration;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTraceSDK\SharedResources\Modules\User\Models\User;
use PactTraceSDK\SharedResources\SDK\Application\Ports\Transactional;
use RuntimeException;

/**
 * Use case behind "Accept Invitation": turns a valid token into a real,
 * client-role login and links it back to the CRM record that was waiting for
 * it — the counterpart to RegisterProvider on the provider side (see that
 * class's docblock for why register()/attachToProvider() are two calls).
 */
class AcceptClientInvitation
{
    public function __construct(
        private readonly ClientInvitationRepository $invitations,
        private readonly ClientRepository $clients,
        private readonly UserRegistration $registration,
        private readonly Transactional $transaction,
    ) {
    }

    /**
     * @return array{0: User, 1: Client}
     *
     * @throws RuntimeException when the token is unknown, expired, or already used —
     *                          the controller maps this to a 410 rather than a 500,
     *                          since an expired invite is an ordinary outcome, not a bug.
     */
    public function handle(string $token, string $password): array
    {
        $invitation = $this->invitations->findValidByToken($token);

        if ($invitation === null) {
            throw new RuntimeException('This invitation link is invalid or has expired.');
        }

        return $this->transaction->run(function () use ($invitation, $password) {
            $client = $invitation->client;

            $user = $this->registration->register($client->name, $invitation->email, $password, Role::Client);
            $this->registration->attachToProvider($user, $invitation->provider_id);

            $client = $this->clients->attachUser((int) $client->getKey(), (int) $user->getKey());

            $this->invitations->markAccepted($invitation);

            return [$user, $client];
        });
    }
}
