<?php

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Concerns\EnforcesPlanGate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\ChangeTeamMemberRole;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\DeactivateTeamMember;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\InviteTeamMember;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\ListTeamMembers;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\ResendTeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\RestoreTeamMember;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\CannotModifyTeamMemberException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\TeamInvitationNotAcceptableException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GatedAction;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\TeamInviteFormRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\TeamMemberRoleUpdateRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\TeamInvitationResource;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\TeamMemberResource;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Staff-facing team administration. Behind real `auth:sanctum` (see
 * routes/api.php) — the invitee's own accept flow lives on the separate,
 * public TeamInvitationController.
 */
class TeamController extends Controller
{
    use EnforcesPlanGate;

    public function __construct(
		private readonly InviteTeamMember $inviteTeamMember,
		private readonly ListTeamMembers $listMember,
	)
    {}

    /**
     * GET /api/v1/team/members — the /dashboard/team members table.
     *
     * The list is a merge of two sources (real `users` rows + still-pending
     * `team_invitations` rows), assembled by ListTeamMembers /
     * TeamMemberHandler. That handler returns a plain Collection, so there is
     * no query-level paginator to lean on — the page is sliced here and
     * wrapped in a LengthAwarePaginator so the response still carries the
     * standard `data` / `links` / `meta` blocks every other list endpoint
     * emits (same shape as MattersController::index()).
     *
     * The visible role set is resolved server-side from the *acting user's*
     * own role, not trusted from the query string: the provider owner row is
     * never returned, and an Admin caller only ever sees Staff members
     * (ListTeamMembers) — a hand-crafted `?filter=admin`/`owner` cannot widen
     * that.
     *
     * Query params: `filter` (all|owner|admin|staff), `status`
     * (active|archived — the /dashboard/team tab; 'active' folds in pending
     * invitations, 'archived' is soft-deactivated members only), `page`,
     * `per_page` (defaults 15, clamped 1..100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $providerId = (int) $request->user()->provider_id;

        $status = $request->query('status') === 'archived' ? 'archived' : 'active';

        /** @var Collection<int, User|TeamInvitation> $members */
        $members = $this->listMember->handle($providerId, $request->user(), $status);

        $filter = (string) $request->query('filter', 'all');
        if (in_array($filter, [Role::Owner->value, Role::Admin->value, Role::Staff->value], true)) {
            $members = $members
                ->filter(fn ($member) => $this->memberRole($member) === $filter)
                ->values();
        }

        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));
        $page = max(1, (int) $request->integer('page', 1));

        $paginator = new LengthAwarePaginator(
            $members->forPage($page, $perPage)->values(),
            $members->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return TeamMemberResource::collection($paginator);
    }

    /**
     * The role string for a merged-list entry — a real `users` row resolves it
     * through spatie (most privileged wins), a pending invitation carries it
     * as an enum cast.
     */
    private function memberRole(User|TeamInvitation $member): ?string
    {
        if ($member instanceof User) {
            return $member->primaryRole()?->value;
        }

        return $member->role instanceof Role ? $member->role->value : $member->role;
    }

    /**
     * POST /api/v1/team/members — invite an owner/staff member.
     *
     * Creates a `team_invitations` row, never a `users` row. Authorised by the
     * `user.invite` permission (Owner only, per Role::permissions()); a staff
     * user without it gets a 403 here, not merely a hidden button.
     */
    public function store(TeamInviteFormRequest $request): JsonResponse
    {
        Gate::authorize('invite', User::class);

        if ($response = $this->denyIfPlanGateFails(GatedAction::InviteStaff, $request->user())) {
            return $response;
        }

        $invitation = $this->inviteTeamMember->handle($request->validated(), $request->user());

        return response()->json([
            'data' => new TeamInvitationResource($invitation),
        ], 201);
    }

    /**
     * POST /api/v1/team/invitations/{invitation}/resend — re-send a pending
     * invite's email with a fresh token.
     *
     * Same gate as inviting (`invite` / `user.invite`, Owner only) — whoever
     * can invite can resend; there is no separate "can resend" concept. The
     * `{invitation}` route-model binding 404s an unknown id; the explicit
     * provider check 404s (not 403 — don't confirm it exists) another
     * tenant's invitation. Per-invitation throttle is on the route.
     */
    public function resend(
        Request $request,
        TeamInvitation $invitation,
        ResendTeamInvitation $useCase,
    ): JsonResponse {
        Gate::authorize('invite', User::class);

        abort_unless(
            (int) $invitation->provider_id === (int) $request->user()->provider_id,
            404,
        );

        try {
            $invitation = $useCase->handle($invitation, $request->user());
        } catch (TeamInvitationNotAcceptableException $e) {
            // Reachable only for reason 'accepted' — a real login exists now,
            // there is nothing to resend. A clear 422 the frontend renders as
            // its own message, not a bare 404/500.
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json([
            'data' => new TeamInvitationResource($invitation),
        ]);
    }

    public function show(string $id)
    {
        //
    }

    /**
     * PATCH /api/v1/team/members/{member} — change a teammate's role.
     *
     * Owner-only, and deliberately stricter than invite/resend and than
     * deactivate/restore: the `manageMembers` gate requires the caller to *be*
     * the provider owner (`providers.owner_user_id`), not merely to hold
     * `user.update` (Admin holds that). This action is unaffected by the
     * wider `changeMemberStatus` rule — an Admin can deactivate/restore a
     * Staff member but still cannot change anyone's role. `{member}` is
     * implicit-bound by id; the explicit provider check 404s another tenant's
     * user rather than trusting the id, and never confirms it exists. The two
     * per-target invariants — can't target yourself, can't target the owner —
     * are enforced inside ChangeTeamMemberRole (TeamMembershipRules) and
     * surfaced here as a 422 `{message, reason}`, not a bare 500.
     */
    public function update(
        TeamMemberRoleUpdateRequest $request,
        User $member,
        ChangeTeamMemberRole $useCase,
    ): JsonResponse {
        Gate::authorize('manageMembers', User::class);

        abort_unless(
            (int) $member->provider_id === (int) $request->user()->provider_id,
            404,
        );

        try {
            $member = $useCase->handle(
                $member,
                Role::from($request->validated('role')),
                $request->user(),
            );
        } catch (CannotModifyTeamMemberException $e) {
            return $this->rejectModification($e);
        }

        return response()->json([
            'data' => new TeamMemberResource($member->refresh()),
        ]);
    }

    /**
     * DELETE /api/v1/team/members/{member} — deactivate a teammate.
     *
     * Gated on `changeMemberStatus`: the Owner, or an Admin acting on a Staff
     * target (the Staff-only restriction is a domain guard in
     * TeamMembershipRules::assertStatusChangeAllowed(), surfaced here as a 422
     * `reason: staff_only`). "Remove" is a soft deactivation, never a hard
     * delete (see UserRepository::deactivate() / DeactivateTeamMember). Their
     * assigned matters fall back to the owner. Same tenant check as update().
     * 204 on success; 422 `{message, reason}` for a blocked target.
     */
    public function destroy(
        Request $request,
        User $member,
        DeactivateTeamMember $useCase,
    ): JsonResponse {
        Gate::authorize('changeMemberStatus', User::class);

        abort_unless(
            (int) $member->provider_id === (int) $request->user()->provider_id,
            404,
        );

        try {
            $useCase->handle($member, $request->user());
        } catch (CannotModifyTeamMemberException $e) {
            return $this->rejectModification($e);
        }

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/team/members/{member}/restore — bring a deactivated
     * teammate back.
     *
     * The inverse of destroy(), and gated identically (`changeMemberStatus` —
     * Owner, or Admin on a Staff target). Restoring does not re-assign the
     * member's old matters back (see RestoreTeamMember). Returns the refreshed
     * member row; 422 `{message, reason}` for a blocked target.
     */
    public function restore(
        Request $request,
        User $member,
        RestoreTeamMember $useCase,
    ): JsonResponse {
        Gate::authorize('changeMemberStatus', User::class);

        abort_unless(
            (int) $member->provider_id === (int) $request->user()->provider_id,
            404,
        );

        try {
            $member = $useCase->handle($member, $request->user());
        } catch (CannotModifyTeamMemberException $e) {
            return $this->rejectModification($e);
        }

        return response()->json([
            'data' => new TeamMemberResource($member->refresh()),
        ]);
    }

    /**
     * A blocked role change / status change — a structural invariant, not a
     * permission or validation failure. 422 with a `reason` the frontend
     * renders as its own message (mirrors TeamController::resend()).
     */
    private function rejectModification(CannotModifyTeamMemberException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'reason' => $e->reason,
        ], 422);
    }
}
