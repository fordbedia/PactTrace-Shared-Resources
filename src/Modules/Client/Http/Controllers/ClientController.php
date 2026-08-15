<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use PactTraceSDK\SharedResources\Modules\Client\Application\Action\ListClientsHandler;
use PactTraceSDK\SharedResources\Modules\Client\Application\UseCases\InviteClient;
use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientListData;
use PactTraceSDK\SharedResources\Modules\Client\Http\Resources\ClientResource;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use Illuminate\Http\Request;
use PactTraceSDK\SharedResources\Modules\Client\Http\Requests\ClientFormRequest;
use PactTraceSDK\SharedResources\Modules\Notification\Application\DTO\ClientInvitationData;
use PactTraceSDK\SharedResources\Modules\Notification\Mail\ClientInvitationEmail;
use PactTraceSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;

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
    public function store(ClientFormRequest $request, InviteClient $handler)
    {
		$data = ClientData::fromRequest($request, auth()->user()->provider_id);

		[$client, $invitation] = $handler->handle($data, auth()->id());

		$resource = new ClientResource($client);

		$provider = auth()->user()->provider?->toArray();

		// Email client that they've been invited, with a link carrying the
		// token AcceptClientInvitation will need to let them set a password.
		$invitationData = ClientInvitationData::fromClientData($data, auth()->user()->name, $invitation->token);
		Mail::to($data->email)->send(new ClientInvitationEmail(ProviderData::fromArray($provider), $invitationData));

		return $resource;
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
