<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Application\DTO;

use Illuminate\Foundation\Http\FormRequest;

final readonly class ClientData
{
	public function __construct(
		public int $provider_id,
		/**
		 * The `clients.user_id` FK — the client's *own* portal login, not the
		 * provider-side actor doing the inviting. Almost always null here:
		 * a brand-new invite has no client login yet, and AcceptClientInvitation
		 * is what fills this in once they accept. Only non-null when the
		 * caller explicitly passes an existing user id to link (the optional
		 * `user_id` field on ClientFormRequest) — never the authenticated
		 * owner/staff member's own id. See .claude/rules/client.md.
		 */
		public ?int $user_id,
		public string $name,
		public string $email,
		public ?string $company_name,
		public ?string $phone
	)
	{}

	public static function fromRequest(
		FormRequest $request,
		int $provider_id,
	): self {
		$payload = $request->validated();
		return new self(
			$provider_id,
			$payload['user_id'] ?? null,
			$payload['name'],
			$payload['email'],
			$payload['company_name'],
			$payload['phone'] ?? null,
		);
	}
}