<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use PactTraceSDK\SharedResources\Modules\Client\Application\Action\CreateClientHandler;
use PactTraceSDK\SharedResources\Modules\Client\Application\Action\ListClientsHandler;
use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientListData;
use PactTraceSDK\SharedResources\Modules\Client\Http\Resources\ClientResource;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use Illuminate\Http\Request;
use PactTraceSDK\SharedResources\Modules\Client\Http\Requests\ClientFormRequest;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, ListClientsHandler $handler)
    {
        Gate::authorize('viewAny', Client::class);

        $data = ClientListData::fromRequest($request, auth()->user()->provider_id);

        return ClientResource::collection($handler->handle($data));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ClientFormRequest $request, CreateClientHandler $handler)
    {
		$data = ClientData::fromRequest($request, auth()->user()->provider_id, auth()->user()->id);

		return new ClientResource($handler->handle($data));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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
