<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserAuthentication;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\AcceptTeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\AcceptTeamInvitationRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\UserResource;
use RuntimeException;

/**
 * The invitee-facing half of the team invitation flow — the page reached from
 * the "Accept Invitation" email. Both actions are unauthenticated: a
 * brand-new team member has no session, so the token itself is what proves
 * they belong here (same reasoning as ClientInvitationController and the
 * deliberately-unauthenticated logout route).
 */
class TeamInvitationController extends Controller
{
    public function __construct(
        private readonly TeamInvitationRepository $invitations,
    ) {
    }

    /**
     * The invitee-facing accept link embedded in the invitation email
     * (built by InviteTeamMember). It points at the frontend accept page,
     * which then drives the two actions below — `show()` to render who
     * invited them and to where, then `accept()` to POST their name +
     * password against the same token. Kept here, on the controller that
     * consumes the token, so the URL shape has one owner.
     */
    public static function acceptUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            . '/accept-invitation/team?token=' . $token;
    }

    /**
     * GET /api/v1/team/invitations/{token}
     *
     * Lets the accept-invitation page render who invited them and to where,
     * and say something concrete when a link is dead instead of failing only
     * on submit. 404 = unknown link; 410 = expired or already used.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->findByToken($token);

        if ($invitation === null) {
            return response()->json([
                'message' => 'This invitation link is invalid.',
            ], 404);
        }

        if (! $invitation->isPending()) {
            return response()->json([
                'message' => 'This invitation link has expired or was already used.',
            ], 410);
        }

        $invitation->loadMissing('provider');

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'title' => $invitation->title,
            'provider_name' => $invitation->provider->business_name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/team/invitations/{token}/accept
     *
     * Creates the team member's login, attaches it to the tenant, and signs
     * them straight in — mirroring RegistrationController / ClientInvitationController.
     */
    public function accept(
        AcceptTeamInvitationRequest $request,
        string $token,
        AcceptTeamInvitation $useCase,
        UserAuthentication $authentication,
    ): JsonResponse {
        try {
            $user = $useCase->handle(
                $token,
                $request->string('name')->toString(),
                $request->string('password')->toString(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 410);
        }

        $authentication->login($user);

        return response()->json([
            'user' => new UserResource($user->loadAuthPayload()),
        ]);
    }
}
