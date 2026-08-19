<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\ResolveEnvelopeBrand;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\UnsupportedDocumentFormatException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use RuntimeException;
use Throwable;

/**
 * Use case behind Flow A (tenant/staff prepares a document for signature) —
 * see .claude/rules/signature.md. Creates the draft DocuSign envelope with
 * the client already attached as its one recipient (required up front for
 * DocuSign's embedded views to resolve later), applies the tenant's
 * Signing Brand if one is configured, and hands back a URL to DocuSign's
 * own hosted Sender View — embedded in an iframe inside PactTrack, where
 * the tenant tags fields, adds/confirms the recipient, and sends, all using
 * DocuSign's native UI.
 *
 * Goes through the Document module's DocumentStorage port (see
 * .claude/rules/document.md) rather than the Storage facade directly, so
 * this always reads from the same disk DocumentUploadService wrote to.
 */
class PrepareEnvelopeForSignature
{
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
     */
    public function handle(Document $document): Envelope
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

        $existing = Envelope::query()
            ->where('document_id', $document->id)
            ->where('status', 'draft')
            ->where('provider', 'docusign')
            ->whereNotNull('provider_envelope_id')
            ->first();

        if ($existing !== null) {
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

        $recipient = new EnvelopeRecipient(
            name: $client->name,
            email: $client->email,
            clientUserId: (string) $client->id,
        );

        $providerEnvelopeId = $this->eSignatureProvider->createDraftEnvelope(
            title: $document->name,
            fileName: $document->name,
            fileContents: $this->documentStorage->get($document->s3_path),
            recipient: $recipient,
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
            . '/docusign-return?flow=sender&envelope=' . $envelope->id;
    }
}
