<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\CreateWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\DeactivateWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\GetWorkspaceDeactivationEligibility;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\ListProviderWorkspaces;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\SwitchActiveWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\UpdateWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Exceptions\WorkspaceDeactivationBlockedException;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Exceptions\WorkspaceDeactivationConfirmationException;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Services\WorkspaceDeactivationPolicy;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceDeactivationBlocker;
use PactTrackSDK\SharedResources\Modules\Workspace\Http\Requests\DeactivateWorkspaceRequest;
use PactTrackSDK\SharedResources\Modules\Workspace\Http\Requests\StoreWorkspaceRequest;
use PactTrackSDK\SharedResources\Modules\Workspace\Http\Resources\WorkspaceResource;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * The Account Settings "Deactivate Workspace" surface — the Workspace module's
 * first HTTP controller.
 *
 * Behind real `auth:sanctum` (new surface, so it follows the
 * MattersController / EnvelopeDetailController pattern rather than the
 * `ResolvesActingUser` bypass some older controllers still carry).
 *
 *   GET    /api/v1/workspaces                                  list for the modal
 *   GET    /api/v1/workspaces/{workspace}/deactivation-eligibility  pre-flight
 *   DELETE /api/v1/workspaces/{workspace}                      confirmed submit
 *
 * `{workspace}` binds by primary key (internal id) — this is a staff-only,
 * authenticated surface, so an enumerable id is not the concern it is on a
 * public client-facing URL. A cross-tenant id is a 404 before the policy ever
 * runs (never trust the id), then `WorkspacePolicy::delete`
 * (permission `workspace.delete`, owner-only by seeding) gates the two
 * per-workspace actions.
 */
class WorkspaceController extends Controller
{
    public function __construct(
        private readonly ListProviderWorkspaces $listWorkspaces,
        private readonly GetWorkspaceDeactivationEligibility $eligibility,
        private readonly DeactivateWorkspace $deactivateWorkspace,
        private readonly CreateWorkspace $createWorkspace,
        private readonly UpdateWorkspace $updateWorkspace,
        private readonly SwitchActiveWorkspace $switchActiveWorkspace,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Workspace::class);

        return WorkspaceResource::collection(
            $this->listWorkspaces->handle((int) $request->user()->provider_id)
        );
    }

    /**
     * POST /api/v1/workspaces — create an additional workspace.
     *
     * Permission `workspace.create` (owner + admin by seeding); a staff user
     * gets a 403. The provider/owner written to the new row are the acting
     * user's own, never request input.
     */
    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        Gate::authorize('create', Workspace::class);

        $workspace = $this->createWorkspace->handle(
            providerId: (int) $request->user()->provider_id,
            ownerId: (int) $request->user()->id,
            name: (string) $request->validated('name'),
            workspaceType: (string) $request->validated('workspace_type'),
            clientLabel: $request->validated('client_label'),
            engagementLabel: $request->validated('engagement_label'),
        );

        return WorkspaceResource::make($workspace)->response()->setStatusCode(201);
    }

    /**
     * PUT /api/v1/workspaces/{workspace} — edit name / type / labels.
     *
     * The onboarding "finish setting up my sole workspace" branch of
     * /dashboard/create-workspace calls this (that workspace already exists —
     * a POST would mint a duplicate). Permission `workspace.update` (owner +
     * staff + admin). Cross-tenant id is a 404 before the policy.
     */
    public function update(StoreWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        $this->abortIfCrossTenant($request, $workspace);
        Gate::authorize('update', $workspace);

        $updated = $this->updateWorkspace->handle(
            $workspace,
            (string) $request->validated('name'),
            (string) $request->validated('workspace_type'),
            $request->validated('client_label'),
            $request->validated('engagement_label'),
        );

        return WorkspaceResource::make($updated)->response();
    }

    /**
     * POST /api/v1/workspaces/{workspace}/activate — make this the actor's
     * active workspace (session + `users.default_workspace_id`).
     *
     * Authorised with `view`, not a structural permission — switching into a
     * workspace you can already see is available to every provider-side role.
     * Cross-tenant id is a 404 before the policy.
     */
    public function activate(Request $request, Workspace $workspace): JsonResponse
    {
        $this->abortIfCrossTenant($request, $workspace);
        Gate::authorize('view', $workspace);

        $this->switchActiveWorkspace->handle($request->user(), $workspace);

        return WorkspaceResource::make($workspace)->response();
    }

    public function deactivationEligibility(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        $blockers = WorkspaceDeactivationPolicy::blockers(
            $this->eligibility->handle($workspace)
        );

        return response()->json([
            'eligible' => $blockers === [],
            'blockers' => $this->serializeBlockers($blockers),
        ]);
    }

    public function destroy(DeactivateWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        try {
            $this->deactivateWorkspace->handle(
                $request->user(),
                $workspace,
                (string) $request->validated('name'),
                (string) $request->validated('password'),
            );
        } catch (WorkspaceDeactivationBlockedException $e) {
            return response()->json([
                'message' => "This workspace can't be deactivated yet.",
                'reason' => 'blocked',
                'blockers' => $this->serializeBlockers($e->blockers),
            ], 422);
        } catch (WorkspaceDeactivationConfirmationException $e) {
            $field = $e->reason === 'name' ? 'name' : 'password';
            $message = $e->reason === 'name'
                ? "That doesn't match the name on your account."
                : 'That password is incorrect.';

            throw ValidationException::withMessages([$field => [$message]]);
        }

        return response()->json(null, 204);
    }

    /**
     * A workspace belonging to another provider is a 404, never a 403 — the
     * bound id is never trusted before this runs.
     */
    private function abortIfCrossTenant(Request $request, Workspace $workspace): void
    {
        abort_unless(
            (int) $workspace->provider_id === (int) $request->user()->provider_id,
            404
        );
    }

    /**
     * Cross-tenant 404, then the permission + tenant check `WorkspacePolicy::delete`
     * runs — for the two "Deactivate Workspace" actions.
     */
    private function authorizeWorkspace(Request $request, Workspace $workspace): void
    {
        $this->abortIfCrossTenant($request, $workspace);

        Gate::authorize('delete', $workspace);
    }

    /**
     * @param  list<WorkspaceDeactivationBlocker>  $blockers
     * @return list<array{code: string, label: string, detail: string}>
     */
    private function serializeBlockers(array $blockers): array
    {
        return array_map(static fn (WorkspaceDeactivationBlocker $blocker): array => [
            'code' => $blocker->value,
            'label' => $blocker->label(),
            'detail' => $blocker->resolution(),
        ], $blockers);
    }
}
