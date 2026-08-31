<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserAuthentication;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\AcceptTeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\GetTeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\TeamInvitationNotAcceptableException;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\AcceptTeamInvitationRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\PublicTeamInvitationResource;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\UserResource;

/**
 * The invitee-facing half of the team invitation flow — the page reached from
 * the "Accept Invitation" email. Both actions are unauthenticated: a
 * brand-new team member has no session, so the token itself is what proves
 * they belong here (same reasoning as ClientInvitationController and the
 * deliberately-unauthenticated logout route). Both routes are throttled in
 * routes/api.php — the token is a bearer credential.
 */
class TeamInvitationController extends Controller
{
    /**
     * The invitee-facing accept link embedded in the invitation email
     * (built by SendTeamInvitationEmail). It points at the frontend accept
     * page, which then drives the two actions below — `show()` to render who
     * invited them and to where, then `accept()` to POST their name +
     * password against the same token. Kept here, on the controller that
     * consumes the token, so the URL shape has exactly one owner.
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
     * on submit. 404 = unknown link; 410 = expired or already used, told
     * apart by the `reason` field so the page can word each case.
     */
    public function show(string $token, GetTeamInvitation $useCase): JsonResponse
    {
        $invitation = $useCase->handle($token);

        if ($invitation === null) {
            return $this->problem(TeamInvitationNotAcceptableException::unknown());
        }

        if (($reason = $invitation->unusableReason()) !== null) {
            return $this->problem(TeamInvitationNotAcceptableException::forReason($reason));
        }

        return response()->json(new PublicTeamInvitationResource($invitation));
    }

    /**
     * POST /api/v1/team/invitations/{token}/accept
     *
     * Re-validates the token server-side (never trusts that show() ran first),
     * creates the team member's login, attaches it to the tenant, and signs
     * them straight in — mirroring RegistrationController /
     * ClientInvitationController. Auto sign-in because a new hire should land
     * in the dashboard, not on a login form for the password they just set.
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
        } catch (TeamInvitationNotAcceptableException $e) {
            return $this->problem($e);
        }

        $authentication->login($user);

        return response()->json([
            'user' => new UserResource($user->loadAuthPayload()),
        ]);
    }

    /**
     * One shape for every "this link can't be used" outcome, on both actions:
     * `{ message, reason }` with the status the exception itself decides
     * (404 unknown / 410 expired|accepted).
     */
    private function problem(TeamInvitationNotAcceptableException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'reason' => $e->reason,
        ], $e->httpStatus());
    }
}
