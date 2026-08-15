<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientInvitationRepository;
use PactTraceSDK\SharedResources\Modules\Client\Application\UseCases\AcceptClientInvitation;
use PactTraceSDK\SharedResources\Modules\Client\Http\Requests\AcceptClientInvitationRequest;
use PactTraceSDK\SharedResources\Modules\User\Application\Services\UserAuthentication;
use PactTraceSDK\SharedResources\Modules\User\Http\Resources\UserResource;
use RuntimeException;

/**
 * Inbound adapter for the client-facing half of the invitation flow — the
 * page a client lands on from the "Accept Invitation" email button. Both
 * actions are intentionally unauthenticated: a brand-new client has no
 * session yet, so the token itself is what proves they're allowed to be here.
 */
class ClientInvitationController extends Controller
{
    public function __construct(
        private readonly ClientInvitationRepository $invitations,
    ) {
    }

    /**
     * GET /api/v1/client-invitations/{token}
     *
     * Lets `/accept-invitation` show who invited the client (and to where)
     * before asking them to set a password, and gives it something concrete
     * to say when a link is expired or was already used, instead of a bare
     * form that fails only on submit.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->findValidByToken($token);

        if ($invitation === null) {
            return response()->json([
                'message' => 'This invitation link is invalid or has expired.',
            ], 404);
        }

        $invitation->loadMissing(['client', 'provider']);

        return response()->json([
            'client_name' => $invitation->client->name,
            'email' => $invitation->email,
            'provider_name' => $invitation->provider->business_name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/client-invitations/{token}/accept
     *
     * Creates the client's login, links it to their `clients` row, and signs
     * them straight in — mirroring RegistrationController, which does the
     * same for a new provider owner.
     */
    public function accept(
        AcceptClientInvitationRequest $request,
        string $token,
        AcceptClientInvitation $useCase,
        UserAuthentication $authentication,
    ): JsonResponse {
        try {
            [$user] = $useCase->handle($token, $request->string('password')->toString());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 410);
        }

        $authentication->login($user);

        return response()->json([
            'user' => new UserResource($user->loadAuthPayload()),
        ]);
    }
}
