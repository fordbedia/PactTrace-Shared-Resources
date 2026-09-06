<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\Client\Application\UseCases\GetClientNotificationSummary;

/**
 * Inbound adapter for the client-portal notification bell — see
 * .claude/rules/client.md, "Client portal notification bell".
 *
 * Same auth shape as `PortalMatterController` / `PortalMessagingController`
 * (`ResolvesActingUser`, not the `auth:sanctum` route middleware) so it runs
 * under this package's Testbench harness, which has no `sanctum` guard. No
 * policy: the summary is inherently self-scoped — the client id is read from
 * the acting user's own `Client` row, so there's no other client's data it
 * could reach.
 */
class PortalNotificationController extends Controller
{
    use ResolvesActingUser;

    public function __construct(
        private readonly GetClientNotificationSummary $useCase,
    ) {
    }

    /**
     * GET /api/v1/portal/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        // A provider-side user has no linked Client row — the resource simply
        // doesn't exist for them (404, not 403, per the "fails closed" rule
        // TenantScopedPolicy already documents for an unlinked client user).
        if ($user === null || $user->client === null) {
            abort(404);
        }

        return response()->json([
            'data' => $this->useCase->handle($user->client->id)->toArray(),
        ]);
    }
}
