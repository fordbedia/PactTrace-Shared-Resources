<?php

namespace PactTraceSDK\SharedResources\Modules\Notification\Application\DTO;

use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;

class ClientInvitationData
{
	public function __construct(
		public ?string $clientName,
		public string $invitedByName,
		public string $email,
		public string $acceptUrl,
	)
	{}

	/**
	 * @param  string  $token  The `client_invitations.token` just issued for
	 *                         this invite — see InviteClient. Embedded as a
	 *                         query param so `/accept-invitation` can look the
	 *                         invitation up without requiring the client to be
	 *                         signed in first (they have no account yet).
	 */
	public static function fromClientData(ClientData $data, string $invitedByName, string $token): self
	{
		return new self(
			clientName: $data->name,
			invitedByName: $invitedByName,
			email: $data->email,
			acceptUrl: rtrim(config('app.frontend_url'), '/') . '/accept-invitation?token=' . $token,
		);
	}
}
