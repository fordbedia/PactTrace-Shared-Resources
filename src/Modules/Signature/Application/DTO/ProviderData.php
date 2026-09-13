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
		// The publicly-reachable URL for `logo_path`, resolved by the caller
		// through the User module's `ProviderLogoStorage` port BEFORE building
		// this DTO — `logo_path` alone is just a storage key (e.g.
		// `provider-logos/13/uuid-name.png`) with no meaning outside that
		// adapter, so a Mailable rendering `<img src="{{ $logo_path }}">`
		// produces a relative path with no scheme/host. This DTO stays
		// decoupled from the storage port itself (no Eloquent model or
		// container resolution here) — see each `fromArray()` call site for
		// how `logo_url` is actually computed.
		public ?string $logo_url = null,
		public ?string $primary_color = null,
		public ?string $secondary_color = null,
		public ?string $trial_ends_at = null,
		// Email Branding fields (/dashboard/branding, "Email Branding" card)
		// — see .claude/rules/branding.md. `email_sender_name`/`email_reply_to`
		// are ungated (every plan, per Ed 2026-09-12: deliverability settings,
		// not visual branding); the "Powered by PactTrack" footer toggle is
		// gated on `allowsCustomBranding()` — see showsPoweredByFooter().
		public ?string $email_sender_name = null,
		public ?string $email_reply_to = null,
		// Defaults false, not true: see the 2026-09-13 migration
		// (default_email_powered_by_footer_to_false) for why — this column
		// went from unused to actually respected in the same change, and
		// `true` would have silently reversed the already-shipped
		// "no PactTrack mark anywhere" Professional/Firm decision.
		public bool $email_powered_by_footer = false,
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

	/**
	 * Whether a client-facing email's footer should carry a "Powered by
	 * PactTrack" line. A Starter tenant's footer is always PactTrack's own
	 * in full (see the client-email-footer partial) regardless of this
	 * toggle — the toggle only has an effect once a tenant's plan actually
	 * allows removing PactTrack branding at all (Professional/Firm), where
	 * it defaults to showing a small co-branding line and can be turned off
	 * for a fully clean, white-labeled footer.
	 */
	public function showsPoweredByFooter(): bool
	{
		return ! $this->allowsCustomBranding() || $this->email_powered_by_footer;
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
			logo_url: $data['logo_url'] ?? null,
			primary_color: $data['primary_color'] ?? null,
			secondary_color: $data['secondary_color'] ?? null,
			trial_ends_at: $data['trial_ends_at'] ?? null,
			email_sender_name: $data['email_sender_name'] ?? null,
			email_reply_to: $data['email_reply_to'] ?? null,
			email_powered_by_footer: $data['email_powered_by_footer'] ?? false,
		);
	}
}