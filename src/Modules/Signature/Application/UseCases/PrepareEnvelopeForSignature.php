<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTraceSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTraceSDK\SharedResources\Modules\Signature\Models\Envelope;
use RuntimeException;

/**
 * Use case behind the "Prepare for Signature" action on /dashboard/documents.
 *
 * Deliberately does NOT create recipients or fields — see the note on
 * ESignatureProvider. This only gets the document's bytes into a draft
 * Documenso envelope and hands back a link to Documenso's own hosted editor,
 * where the attorney drags on signature/date fields and adds recipients
 * using Documenso's native UI (self-hosted AGPL includes this UI for free;
 * embedding that same UI inside PactTrace is an Enterprise-only add-on —
 * see the Signature module rules doc).
 *
 * Goes through the Document module's DocumentStorage port (see
 * .claude/rules/document.md) rather than the Storage facade directly, so
 * this always reads from the same disk UploadDocument wrote to —
 * previously this used `config('filesystems.default')` while uploads went
 * through `config('filesystems.document_disk')`, which happened to be the
 * same disk in local dev but would silently break the moment they diverged
 * (e.g. DOCUMENT_STORAGE_DISK=s3 with a different FILESYSTEM_DISK).
 */
class PrepareEnvelopeForSignature
{
    public function __construct(
        private readonly ESignatureProvider $eSignatureProvider,
        private readonly DocumentStorage $documentStorage,
    ) {
    }

    /**
     * Idempotent: re-running this for a document that already has a draft
     * envelope just returns that envelope instead of creating a duplicate
     * one in Documenso.
     */
    public function handle(Document $document): Envelope
    {
        if ($document->client_id === null) {
            throw new RuntimeException(
                'Cannot prepare a signature envelope for a document with no client assigned.'
            );
        }

        $existing = Envelope::query()
            ->where('document_id', $document->id)
            ->where('status', 'draft')
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

        $providerEnvelopeId = $this->eSignatureProvider->createDraftEnvelope(
            title: $document->name,
            fileName: $document->name,
            fileContents: $this->documentStorage->get($document->s3_path),
            externalId: (string) $document->id,
        );

        $envelope = new Envelope([
            'provider_id' => $document->provider_id,
            'document_id' => $document->id,
            'client_id' => $document->client_id,
            'provider_envelope_id' => $providerEnvelopeId,
            'status' => 'draft',
        ]);
        $envelope->save();

        return $envelope;
    }

    public function editorUrlFor(Envelope $envelope): string
    {
        if ($envelope->provider_envelope_id === null) {
            throw new RuntimeException('Envelope has no provider_envelope_id — call handle() first.');
        }

        return $this->eSignatureProvider->editorUrl($envelope->provider_envelope_id);
    }
}
