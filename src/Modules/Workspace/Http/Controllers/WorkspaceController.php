<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\DeactivateWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\GetWorkspaceDeactivationEligibility;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\ListProviderWorkspaces;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Exceptions\WorkspaceDeactivationBlockedException;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Exceptions\WorkspaceDeactivationConfirmationException;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Services\WorkspaceDeactivationPolicy;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceDeactivationBlocker;
use PactTrackSDK\SharedResources\Modules\Workspace\Http\Requests\DeactivateWorkspaceRequest;
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
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Workspace::class);

        return WorkspaceResource::collection(
            $this->listWorkspaces->handle((int) $request->user()->provider_id)
        );
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
     * Cross-tenant is a 404 (never trust the bound id), then the permission +
     * tenant check that `WorkspacePolicy::delete` runs.
     */
    private function authorizeWorkspace(Request $request, Workspace $workspace): void
    {
        abort_unless(
            (int) $workspace->provider_id === (int) $request->user()->provider_id,
            404
        );

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
