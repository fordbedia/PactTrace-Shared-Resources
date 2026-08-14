<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Http\Controllers;

use App\Http\Controllers\Controller;
use PactTraceSDK\SharedResources\Modules\Client\Application\Action\CreateClientHandler;
use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use Illuminate\Http\Request;
use PactTraceSDK\SharedResources\Modules\Client\Http\Requests\ClientFormRequest;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ClientFormRequest $request, CreateClientHandler $handler)
    {
		$data = ClientData::fromRequest($request, auth()->user()->provider_id, auth()->user()->id);

		$handler->handle($data);
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
