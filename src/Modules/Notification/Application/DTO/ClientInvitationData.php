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

	public static function fromClientData(ClientData $data, string $invitedByName): self
	{
		return new self(
			clientName: $data->name,
			invitedByName: $invitedByName,
			email: $data->email,
			acceptUrl: rtrim(config('app.frontend_url'), '/') . '/sign-in',
		);
	}
}
