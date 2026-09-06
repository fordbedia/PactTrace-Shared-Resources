<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\DTO;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;

class ProviderData
{
	public function __construct(
		public ?int $id = null,
		public int $owner_user_id,
		public string $business_name,
		public string $subdomain,
		public string $plan = 'professional',
		public ?string $custom_domain = null,
		public ?string $logo_path = null,
		public ?string $primary_color = null,
		public ?string $secondary_color = null,
		public ?string $trial_ends_at = null,
	)
	{}

	/**
	 * Whether this tenant's plan permits white-labeling — the SAME
	 * `Plan::info()->allowsCustomBranding` gate `/dashboard/branding`,
	 * `ResolveEnvelopeBrand` and the client portal use. Drives which
	 * client-facing emails carry the provider's own logo/colour and no
	 * PactTrack footer vs. PactTrack's own branding. Resolves defensively:
	 * an unknown/blank plan string falls back to the smallest tier.
	 */
	public function allowsCustomBranding(): bool
	{
		return (Plan::tryFrom($this->plan) ?? Plan::default())->info()->allowsCustomBranding;
	}

	public static function fromArray(array $data): self
	{
		return new self(
			id: $data['id'] ?? null,
			owner_user_id: $data['owner_user_id'],
			business_name: $data['business_name'],
			subdomain: $data['subdomain'],
			plan: $data['plan'] ?? 'professional',
			custom_domain: $data['custom_domain'] ?? null,
			logo_path: $data['logo_path'] ?? null,
			primary_color: $data['primary_color'] ?? null,
			secondary_color: $data['secondary_color'] ?? null,
			trial_ends_at: $data['trial_ends_at'] ?? null,
		);
	}
}