<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;

/**
 * Outbound port to whatever e-signature provider is configured (DocuSign
 * today, via JWT Grant — see Infrastructure/Docusign/). Swappable behind
 * this same interface per the hexagonal rule in the top-level CLAUDE.md.
 *
 * Wider than the original Documenso-era port on purpose: DocuSign's
 * embedded model needs a distinct sender view (tenant tags fields + sends,
 * inside PactTrack) and recipient view (client signs, inside PactTrack) —
 * see .claude/rules/signature.md, "Flow A" / "Flow B". PactTrack still never
 * models field/tab coordinates itself: both views are DocuSign's own hosted
 * UI, rendered in an iframe; this port only gets a document in, gets a URL
 * to embed, and reads status back out.
 */
interface ESignatureProvider
{
    /**
     * Create a draft envelope in the provider, upload the document's bytes
     * to it, and add every recipient it will be sent to (required up front
     * — DocuSign's embedded views can't resolve a recipient that isn't
     * already on the envelope). `$recipients` is non-empty; its first entry
     * is the document's own client (conventionally assigned the provider's
     * recipientId '1', which recipientViewUrl() below always targets — see
     * DocusignSignatureProvider). Returns the provider's own envelope
     * identifier (persisted locally as Envelope::provider_envelope_id).
     *
     * @param EnvelopeRecipient[] $recipients
     */
    public function createDraftEnvelope(
        string $title,
        string $fileName,
        string $fileContents,
        array $recipients,
        ?string $externalId = null,
    ): string;

    /**
     * Build a URL to the provider's own hosted "prepare and send" UI for
     * this envelope, embedded in an iframe inside PactTrack — where the
     * tenant/staff drags on signature/date/text fields and sends. Not a
     * new-tab link-out; see .claude/rules/signature.md, "Flow A".
     */
    public function senderViewUrl(string $providerEnvelopeId, string $returnUrl): string;

    /**
     * Build a URL to the provider's own hosted signing UI for the given
     * recipient, embedded in an iframe inside the client portal. Returned
     * wrapped in a SigningToken for response-shape stability with the
     * pre-DocuSign contract (frontend/backend already speak `signing_url`);
     * DocuSign's recipient view has no separate reusable "token" the way
     * embedding tokens in other providers do, so `token` here is an opaque
     * value carried along for audit/debugging only — never re-used to mint
     * a second view.
     *
     * `$recipientId` is the provider's own identifier for *this* recipient
     * on the envelope (DocuSign's `recipientId`, assigned positionally —
     * `'1'` for the document's own client, `'2'`/`'3'`/... for co-signers —
     * in createDraftEnvelope() and persisted as Signer::provider_signer_id).
     * Callers must pass the recipient's actual id, not assume `'1'`: this
     * is what lets recipientViewUrl() mint a correct view for a co-signer,
     * not just the primary recipient. See GenerateSigningEmbedTokenUseCase
     * and GenerateGuestSigningEmbedTokenUseCase.
     */
    public function recipientViewUrl(
        string $providerEnvelopeId,
        EnvelopeRecipient $recipient,
        string $returnUrl,
        string $recipientId,
    ): SigningToken;

    /**
     * Apply a Signing Brand (logo/colors/email templates) to a draft
     * envelope. `$brandId === null` is a normal, non-error "send unbranded"
     * outcome — not every plan/tenant has one configured — so this is a
     * no-op when null, not a caller-side branch; see
     * Application/Services/ResolveEnvelopeBrand and
     * .claude/rules/signature.md.
     */
    public function applyBrand(string $providerEnvelopeId, ?string $brandId): void;

    /**
     * Read the envelope's current status directly from the provider. Two
     * callers:
     *
     * 1. Application/UseCases/CheckEnvelopeProviderStatus — optimistic,
     *    read-only UI feedback right after an embedded view's returnUrl
     *    fires. Never persists anything.
     * 2. Application/UseCases/ReconcileStaleEnvelopes — the scheduled
     *    safety net for DocuSign Connect webhook delivery being slow or
     *    missing entirely (draft, and sent/viewed/partially_signed alike).
     *    This *is* allowed to feed a persisted Envelope status change, but
     *    only through RecordSignatureCompletionUseCase (the same
     *    transition/audit-log/notification path a real webhook uses) and
     *    only for envelopes stuck well past a normal webhook delay — see
     *    that use case's docblock. The webhook remains the primary,
     *    preferred path; this is deliberately a fallback, not a
     *    replacement for it.
     */
    public function fetchEnvelopeStatus(string $providerEnvelopeId): string;

    /**
     * Verify an inbound webhook (DocuSign Connect) actually came from the
     * provider before anything in its payload is trusted.
     * `$signatureHeader` is whatever header the provider signs the payload
     * with — see DocusignWebhookController.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool;

    /**
     * Normalize a provider-shaped webhook payload into PactTrack's own
     * vocabulary. Kept on the port (rather than inlined in the webhook
     * controller) so a future provider swap can supply its own mapping
     * without touching RecordSignatureCompletionUseCase.
     */
    public function normalizeWebhookEvent(array $payload): WebhookEvent;
}
