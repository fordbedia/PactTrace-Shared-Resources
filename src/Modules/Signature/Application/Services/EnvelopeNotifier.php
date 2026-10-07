<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\DocumentReadyForSignatureEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\GuestSigningInvitationEmail;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\ProviderLogoStorage;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use Throwable;

class EnvelopeNotifier
{
	public function __construct(
		private readonly GuestSigningTokenService $guestSigningTokenService,
		private readonly ProviderLogoStorage      $providerLogoStorage,
	)
	{
	}

	/**
	 * Best-effort — a mail failure must never break webhook processing
	 * (the status transition and audit log above already succeeded, and
	 * DocuSign would otherwise retry a payload that was actually handled
	 * fine). Follows the same Mailable + DTO pattern as
	 * Notification\Mail\ClientInvitationEmail.
	 */
	public function notifyClient(Envelope $envelope): void
	{
		try {
			$client = $envelope->client()->first();
			$provider = $envelope->provider()->first();
			$document = $envelope->document()->first();

			if ($client === null || $provider === null) {
				return;
			}

			// Respect the recipient's "document ready for signature"
			// preference — but only when they actually have a portal user to
			// hold one. A client still in `invited` state (no user_id yet)
			// has no preferences row; this is their first-contact email, not
			// a nag they opted out of, so it still goes out. See
			// dispatch sites".
			$recipientUser = $client->user()->first();

			if ($recipientUser !== null
				&& !Notification::isset('document_ready_for_signature', $recipientUser)) {
				Log::info('DocumentReadyForSignatureEmail suppressed by recipient notification preference.', [
					'envelope_id' => $envelope->id,
					'client_id' => $client->id,
				]);

				return;
			}

			Mail::to($client->email)->queue(new DocumentReadyForSignatureEmail(
				providerData: ProviderData::fromArray($this->providerDataArray($provider)),
				clientName: $client->name,
				documentName: $document?->name ?? 'A document',
				portalUrl: $this->buildPortalUrl($document),
				workspaceName: (string)($envelope->workspace?->name ?? ''),
			));
		} catch (Throwable $e) {
			report($e);
		}
	}

	/**
	 * PactTrack's own guest signing invitation to every ad-hoc co-signer on
	 * the envelope — the client's own Signer row always carries
	 * `provider_signer_id === '1'` (see DocusignSignatureProvider::RECIPIENT_ID
	 * and PrepareEnvelopeForSignature::createSignerRows, both
	 * positional/1-indexed the same way), so everything else here is a
	 * co-signer, never the client counted twice. Each co-signer is issued a
	 * fresh guest signing token here, at send time — see
	 * GuestSigningTokenService::issueFor() — and signs embedded, through
	 * PactTrack's own branded iframe, not DocuSign's hosted remote-signer
	 * email; see .claude/rules/signature.md, "Guest signers". A co-signer
	 * with no email on file is simply skipped (no token to deliver it with)
	 * rather than treated as an error. Same best-effort contract as
	 * notifyClient(): never let a mail failure break webhook processing.
	 */
	public function notifyCoSigners(Envelope $envelope): void
	{
		try {
			$coSigners = $envelope->signers()
				->where('provider_signer_id', '!=', '1')
				->get();

			foreach ($coSigners as $coSigner) {
				if ($coSigner->email === null || $coSigner->email === '') {
					continue;
				}

				$this->notifyCoSigner($coSigner, $envelope);
			}
		} catch (Throwable $e) {
			report($e);
		}
	}

	public function notifyCoSigner(
		Signer   $coSigner,
		Envelope $envelope,
	): void
	{
		$client = $envelope->client()->first();
		$provider = $envelope->provider()->first();
		$document = $envelope->document()->first();

		if ($provider === null) {
			return;
		}

		$rawToken = $this->guestSigningTokenService->issueFor($coSigner);

		$signingUrl = rtrim((string)config('app.frontend_url'), '/')
			. '/portal/sign?signingLinkToken=' . $rawToken . '&envelope=' . $envelope->public_id;

		Mail::to($coSigner->email)->queue(new GuestSigningInvitationEmail(
			providerData: ProviderData::fromArray($this->providerDataArray($provider)),
			signerName: $coSigner->name,
			documentName: $document?->name ?? 'A document',
			clientName: $client?->name ?? 'the client',
			signingUrl: $signingUrl,
			workspaceName: (string)($envelope->workspace?->name ?? ''),
		));
	}

	/**
	 * `$provider->toArray()` alone leaves `logo_path` as a bare storage key
	 * (e.g. `provider-logos/13/uuid-name.png`) — no scheme, no host.
	 * `DocumentReadyForSignatureEmail`/`GuestSigningInvitationEmail`'s
	 * `<img src="{{ $logoUrl }}">` needs a real, publicly-reachable URL, so
	 * this resolves it through the same `ProviderLogoStorage` port
	 * `ProviderResource.logo_url` and `/dashboard/branding` already use, and
	 * folds it into the array as `logo_url` for `ProviderData::fromArray()`
	 * to pick up. See .claude/rules/branding.md and
	 * .claude/rules/notification.md, "Client-facing vs. internal email
	 * branding".
	 *
	 * @return array<string, mixed>
	 */
	private function providerDataArray(Provider $provider): array
	{
		$data = $provider->toArray();
		$data['logo_url'] = $provider->logo_path !== null
			? $this->providerLogoStorage->url($provider->logo_path)
			: null;

		return $data;
	}

	/**
	 * `{FRONTEND_URL}/portal/matter/{matter.public_id}` when the document
	 * being signed belongs to a Matter — bare `{FRONTEND_URL}/portal`
	 * otherwise, since a Document can exist outside any Matter (see
	 * .claude/rules/matter.md) and `/portal` already handles that case (its
	 * own single-matter redirect / multi-matter picker / empty state — see
	 * .claude/rules/matter.md, "Matter Progress timeline"). Deliberately not
	 * `/portal/sign?envelope=...` — this email's CTA lands the client on the
	 * matter's own portal page, not straight into the DocuSign iframe.
	 */
	private function buildPortalUrl(?Document $document): string
	{
		$base = rtrim((string)config('app.frontend_url'), '/');
		$matterPublicId = $document?->matter?->public_id;

		return $matterPublicId !== null
			? $base . '/portal/matter/' . $matterPublicId
			: $base . '/portal';
	}

}