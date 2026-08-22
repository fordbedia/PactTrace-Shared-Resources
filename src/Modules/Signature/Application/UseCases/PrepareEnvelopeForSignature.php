<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\ResolveEnvelopeBrand;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeAlreadySentException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\UnsupportedDocumentFormatException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use RuntimeException;
use Throwable;

/**
 * Use case behind Flow A (tenant/staff prepares a document for signature) —
 * see .claude/rules/signature.md. Creates the draft DocuSign envelope with
 * the document's client plus any ad-hoc co-signers already attached as its
 * recipients (required up front for DocuSign's embedded views to resolve
 * later), applies the tenant's Signing Brand if one is configured, and
 * hands back a URL to DocuSign's own hosted Sender View — embedded in an
 * iframe inside PactTrack, where the tenant tags fields, confirms
 * recipients, and sends, all using DocuSign's native UI.
 *
 * Goes through the Document module's DocumentStorage port (see
 * .claude/rules/document.md) rather than the Storage facade directly, so
 * this always reads from the same disk DocumentUploadService wrote to.
 */
class PrepareEnvelopeForSignature
{
    /** DocuSign's own envelope status while it's still a sender-editable draft. */
    private const DOCUSIGN_DRAFT_STATUS = 'created';

    public function __construct(
        private readonly ESignatureProvider $eSignatureProvider,
        private readonly DocumentStorage $documentStorage,
        private readonly ResolveEnvelopeBrand $resolveEnvelopeBrand,
    ) {
    }

    /**
     * Idempotent: re-running this for a document that already has a
     * *DocuSign* draft envelope just returns that envelope instead of
     * creating a duplicate one at the provider — this is also what makes
     * "tenant closes the tab mid-preparation" safe: senderViewUrlFor() can
     * simply be called again for the same draft envelope. Scoped to
     * `provider = 'docusign'` so a pre-migration Documenso draft row (a
     * `provider_envelope_id` DocuSign has never heard of) is never mistaken
     * for a reusable draft — see .claude/rules/signature.md.
     *
     * PDF-only: DocuSign's envelope-creation API rejects anything else.
     * DOCX/XLSX/image uploads are guarded out here rather than left to fail
     * against the provider, since converting them to PDF first is out of
     * scope for now — see .claude/rules/signature.md.
     *
     * @param array<array{name: string, email: string}> $coSigners Additional
     *     signers beyond the document's own client, collected by the tenant
     *     in PrepareSignatureModal. Only honored when creating a brand-new
     *     envelope (the $existing-draft branch below always returns as-is)
     *     — adding recipients to an already-created draft is a different
     *     DocuSign operation than creating one, and out of scope for now.
     */
    public function handle(Document $document, array $coSigners = []): Envelope
    {
        if ($document->client_id === null) {
            throw new RuntimeException(
                'Cannot prepare a signature envelope for a document with no client assigned.'
            );
        }

        if ($document->mime_type !== 'application/pdf') {
            throw new UnsupportedDocumentFormatException(
                "Only PDF documents can be prepared for e-signature. This file is [{$document->mime_type}]."
            );
        }

        $existing = Envelope::query()->reusableDraftFor($document->id)->first();

        if ($existing !== null) {
            // Our local `status` only advances on the DocuSign Connect
            // webhook (see .claude/rules/signature.md, "Webhooks"). If that
            // webhook never lands — not configured, or briefly unreachable
            // — this row can still read `draft` long after the tenant
            // actually sent it on DocuSign's side. Reusing it blindly would
            // ask DocuSign for a fresh Sender View on an envelope it no
            // longer considers editable, which DocuSign answers by
            // bouncing the iframe straight back to returnUrl instead of
            // showing its editor — from the tenant's side that looks like
            // the "Prepare for Signature" iframe is just broken. A live
            // check catches that before it happens.
            $providerStatus = $this->eSignatureProvider->fetchEnvelopeStatus($existing->provider_envelope_id);

            if ($providerStatus !== self::DOCUSIGN_DRAFT_STATUS) {
                throw EnvelopeAlreadySentException::forProviderStatus($providerStatus);
            }

            return $existing;
        }

        if (! $this->documentStorage->exists($document->s3_path)) {
            throw new RuntimeException(
                "Document file not found at [{$document->s3_path}]."
            );
        }

        $client = $document->client()->first();

        if ($client === null) {
            throw new RuntimeException("Document [{$document->id}]'s client_id does not resolve to a Client row.");
        }

        $recipients = [
            new EnvelopeRecipient(name: $client->name, email: $client->email, clientUserId: (string) $client->id),
        ];

        foreach ($coSigners as $coSigner) {
            // clientUserId is the co-signer's own email rather than null: a
            // co-signer now signs embedded, through PactTrack's own guest
            // link (see GenerateGuestSigningEmbedTokenUseCase), not
            // DocuSign's hosted remote-signer email — DocuSign requires the
            // same clientUserId at creation and at every later
            // recipientViewUrl() call for an embedded recipient, and email
            // is the only value stable across both that's known before this
            // signer's own Signer row exists. See .claude/rules/signature.md,
            // "Guest signers".
            $recipients[] = new EnvelopeRecipient(name: $coSigner['name'], email: $coSigner['email'], clientUserId: $coSigner['email']);
        }

        $providerEnvelopeId = $this->eSignatureProvider->createDraftEnvelope(
            title: $document->name,
            fileName: $document->name,
            fileContents: $this->documentStorage->get($document->s3_path),
            recipients: $recipients,
            externalId: (string) $document->id,
        );

        $envelope = new Envelope([
            'provider_id' => $document->provider_id,
            'document_id' => $document->id,
            'client_id' => $document->client_id,
            'provider' => 'docusign',
            'provider_envelope_id' => $providerEnvelopeId,
            'status' => 'draft',
        ]);
        $envelope->save();

        $this->createSignerRows($envelope, $recipients);

        // Branding is best-effort and degrades gracefully — a resolution or
        // provider failure here should never undo the envelope that was
        // just successfully created, it should just mean the envelope goes
        // out unbranded. See ResolveEnvelopeBrand and
        // .claude/rules/signature.md.
        try {
            $provider = $document->provider()->first();
            $brandId = $provider !== null ? $this->resolveEnvelopeBrand->handle($provider) : null;
            $this->eSignatureProvider->applyBrand($providerEnvelopeId, $brandId);
        } catch (Throwable $e) {
            report($e);
        }

        return $envelope;
    }

    /**
     * One `pending` Signer row per recipient, in the same order/recipientId
     * DocusignSignatureProvider assigns them (positional, 1-indexed — see
     * ESignatureProvider::createDraftEnvelope) — this is what gives a
     * multi-signer envelope a "who hasn't signed yet" record from the
     * moment it's sent, instead of only once each one completes (see
     * RecordSignatureCompletionUseCase).
     *
     * @param EnvelopeRecipient[] $recipients
     */
    private function createSignerRows(Envelope $envelope, array $recipients): void
    {
        foreach (array_values($recipients) as $index => $recipient) {
            Signer::create([
                'envelope_id' => $envelope->id,
                'provider_signer_id' => (string) ($index + 1),
                'name' => $recipient->name,
                'email' => $recipient->email,
                'routing_order' => 1,
                'status' => 'pending',
            ]);
        }
    }

    public function senderViewUrlFor(Envelope $envelope): string
    {
        if ($envelope->provider_envelope_id === null) {
            throw new RuntimeException('Envelope has no provider_envelope_id — call handle() first.');
        }

        return $this->eSignatureProvider->senderViewUrl($envelope->provider_envelope_id, $this->returnUrl($envelope));
    }

    /**
     * Where DocuSign's Sender View redirects the iframe once the tenant
     * sends/saves/cancels — the shared frontend route that immediately
     * postMessage()s the parent window and gets closed, never a page meant
     * to be viewed on its own. See frontend/app/docusign-return/page.js,
     * which PrepareSignatureModal filters on `flow=sender` for.
     */
    private function returnUrl(Envelope $envelope): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            . '/docusign-return?flow=sender&envelope=' . $envelope->public_id;
    }
}
