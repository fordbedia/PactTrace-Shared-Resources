<?php

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\InviteTeamMember;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\ListTeamMembers;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\TeamInviteFormRequest;
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
     * Query params: `filter` (all|owner|staff), `page`, `per_page`
     * (defaults 15, clamped 1..100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $providerId = (int) $request->user()->provider_id;

        /** @var Collection<int, User|TeamInvitation> $members */
        $members = $this->listMember->handle($providerId);

        $filter = (string) $request->query('filter', 'all');
        if (in_array($filter, [Role::Owner->value, Role::Staff->value], true)) {
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

        $invitation = $this->inviteTeamMember->handle($request->validated(), $request->user());

        return response()->json([
            'data' => new TeamInvitationResource($invitation),
        ], 201);
    }

    public function show(string $id)
    {
        //
    }

    public function update(Request $request, string $id)
    {
        //
    }

    public function destroy(string $id)
    {
        //
    }
}
