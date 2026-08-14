<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Application\DTO;

use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Http\FormRequest;

final readonly class ClientData
{
	public function __construct(
		public int $provider_id,
		public int $user_id,
		public string $name,
		public string $company_name,
		public string $email
	)
	{}

	public static function fromRequest(
		FormRequest $request,
		int $provider_id,
		int $user_id
	): self {
		$payload = $request->validated();
		return new self($provider_id, $user_id, $payload['name'], $payload['company_name'], $payload['email']);
	}
}