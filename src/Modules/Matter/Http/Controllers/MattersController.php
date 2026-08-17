<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PactTraceSDK\SharedResources\Modules\Client\Http\Resources\ClientResource;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Action\CountActiveMattersHandler;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Action\CountCompletedMattersHandler;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Action\CountOnHoldMattersHandler;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Action\CountTotalMattersHandler;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Action\CreateMattersHandler;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Action\ListMattersHandler;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Action\SearchMatterClientsHandler;
use PactTraceSDK\SharedResources\Modules\Matter\Application\DTO\ClientSearchData;
use PactTraceSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTraceSDK\SharedResources\Modules\Matter\Application\DTO\MattersListData;
use PactTraceSDK\SharedResources\Modules\Matter\Http\Requests\MattersRequest;
use PactTraceSDK\SharedResources\Modules\Matter\Http\Resources\MatterResource;
use PactTraceSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterCountFormatter;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;

class MattersController extends Controller
{
    /**
     * Clients selectable for a new matter — backs the "Search or select
     * client…" field on the New Matter drawer. Gated on the bare
     * `MatterCreate` permission (no record to scope against yet); tenant and
     * workspace scoping happen in the repository query itself.
     */
    public function searchClients(Request $request, SearchMatterClientsHandler $handler)
    {
        Gate::authorize('create', Matter::class);

        $data = ClientSearchData::fromRequest($request, auth()->user()->provider_id);

        return ClientResource::collection($handler->handle($data));
    }

    /**
     * Display a listing of the resource. Mirrors ClientController::index() —
     * see .claude/rules/client.md — filter chip (`all|active|on_hold|
     * completed|cancelled`) and pagination are handled server-side via
     * ListMattersHandler / EloquentMattersRepository::paginate*.
     */
    public function index(Request $request, ListMattersHandler $handler)
    {
        Gate::authorize('viewAny', Matter::class);

        $data = MattersListData::fromRequest($request, auth()->user()->provider_id);

        return MatterResource::collection($handler->handle($data));
    }

    /**
     * The four stat cards on /dashboard/matters — "Total Matters", "Active",
     * "On Hold", "Completed". One handler per card (CountTotalMattersHandler
     * / CountActiveMattersHandler / CountOnHoldMattersHandler /
     * CountCompletedMattersHandler), each backed by MatterStatsService. Uses
     * the same `viewAny` gate as index() — a stat card is just a different
     * shape of "can this actor list matters". Counts are abbreviated via
     * MatterCountFormatter (1,234 -> "1.2k") before going out — the frontend
     * renders the string as-is, no client-side formatting needed.
     */
    public function stats(
        CountTotalMattersHandler $total,
        CountActiveMattersHandler $active,
        CountOnHoldMattersHandler $onHold,
        CountCompletedMattersHandler $completed,
        MatterCountFormatter $formatter,
    ) {
        Gate::authorize('viewAny', Matter::class);

        $providerId = auth()->user()->provider_id;

        return response()->json([
            'total' => $formatter->format($total->handle($providerId)),
            'active' => $formatter->format($active->handle($providerId)),
            'on_hold' => $formatter->format($onHold->handle($providerId)),
            'completed' => $formatter->format($completed->handle($providerId)),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(
		MattersRequest $request,
		CreateMattersHandler $handler,
		CurrentWorkspace $currentWorkspace,
	) {
		$providerId = auth()->user()->provider_id;

		$workspaceId = $currentWorkspace->id();

		$data = MattersData::fromRequest($providerId, $workspaceId, $request);

		return $handler->handle($data);
    }

    /**
     * Display the specified resource. Not on the frontend's critical path yet
     * — /dashboard/matters' "View" action reads the already-fetched list row
     * instead, same as /dashboard/clients (see .claude/rules/client.md) — but
     * implemented for parity with the eventual matter detail route.
     */
    public function show(Matter $matter)
    {
        Gate::authorize('view', $matter);

        return new MatterResource($matter->load(['client', 'milestones']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
