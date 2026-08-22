<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Action\GetMatterPortalDetailHandler;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Action\ListClientMattersHandler;
use PactTrackSDK\SharedResources\Modules\Matter\Http\Resources\PortalMatterResource;
use PactTrackSDK\SharedResources\Modules\Matter\Http\Resources\PortalMatterSummaryResource;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * Inbound adapter for the client portal's matter list/detail views
 * (`/portal` and `/portal/matter/{matterId}`) — see .claude/rules/matter.md.
 * Kept separate from `MattersController` for the same reason
 * `SigningController` is kept separate from `EnvelopeController` in the
 * Signature module: different audience (a client, not the provider/staff),
 * different payload shape, and a different auth pattern —
 * `ResolvesActingUser` + `Gate::forUser()`, same as `SigningController`,
 * rather than the `auth:sanctum` route middleware `MattersController` uses.
 * `MatterPolicy::view()` already resolves a client-role actor correctly
 * once handed one (`TenantScopedPolicy::visibleToActorsClient()` compares
 * `matters.client_id` against the actor's own `Client::id` directly — no
 * separate client-authorization gate was needed here, see the report
 * accompanying this controller's introduction).
 */
class PortalMatterController extends Controller
{
    use ResolvesActingUser;

    public function __construct(
        private readonly GetMatterPortalDetailHandler $handler,
        private readonly ListClientMattersHandler $listHandler,
    ) {
    }

    /**
     * GET /api/v1/portal/matters
     *
     * Every matter belonging to the signed-in client — what `/portal`
     * resolves against to decide whether to go straight to the one matter a
     * client has, or show a "pick your matter" landing view for a client
     * with more than one (`Matter belongsTo Client` carries no uniqueness
     * constraint — see .claude/rules/matter.md). Scoped by
     * `ListClientMattersHandler`, never `MattersController::index()` — see
     * that handler's own docblock for why reusing it here would leak other
     * clients' matters.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->client === null) {
            return response()->json([
                'message' => 'You must be signed in as a client to view matters.',
            ], 401);
        }

        Gate::forUser($user)->authorize('viewAny', Matter::class);

        return response()->json([
            'data' => PortalMatterSummaryResource::collection($this->listHandler->handle($user->client->id)),
        ]);
    }

    /**
     * GET /api/v1/portal/matters/{matter}
     *
     * `{matter}` binds by `Matter::public_id` (see that model's
     * `getRouteKeyName()`) — never the internal auto-increment id, same
     * reasoning as `Envelope::public_id` (.claude/rules/signature.md).
     */
    public function show(Request $request, Matter $matter): PortalMatterResource|JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->client === null) {
            return response()->json([
                'message' => 'You must be signed in as a client to view a matter.',
            ], 401);
        }

        Gate::forUser($user)->authorize('view', $matter);

        return new PortalMatterResource($this->handler->handle($matter));
    }
}
