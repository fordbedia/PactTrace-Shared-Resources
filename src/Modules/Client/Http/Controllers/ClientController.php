<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Client\Application\Action\ListClientsHandler;
use PactTrackSDK\SharedResources\Modules\Client\Application\Action\SearchClientsHandler;
use PactTrackSDK\SharedResources\Modules\Client\Application\UseCases\InviteClient;
use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientListData;
use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientSearchData;
use PactTrackSDK\SharedResources\Modules\Client\Http\Resources\ClientResource;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\Client\Http\Requests\ClientFormRequest;
use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\ClientInvitationData;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\ClientInvitationEmail;
use PactTrackSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;

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
     * Clients selectable when filing a document — backs the "Search or
     * select client…" field on the Upload Documents modal
     * (/dashboard/documents, see .claude/rules/document.md). Same `viewAny`
     * gate as index() — a search is still just a read of the client list,
     * capped to `limit` (default 10, max 20) instead of paginated, since
     * this backs a live typeahead rather than a browsable table.
     */
    public function search(Request $request, SearchClientsHandler $handler)
    {
        Gate::authorize('viewAny', Client::class);

        $data = ClientSearchData::fromRequest($request, auth()->user()->provider_id);

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
