<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;

/**
 * Resumability read behind Flow A's signer-collection step
 * (PrepareSignatureModal) — lets the frontend show which signers are
 * already committed to a still-open DocuSign draft when the tenant reopens
 * "Prepare for Signature" for a document, instead of resetting to an empty
 * form. Read-only: never creates or mutates anything, and shares
 * Envelope::scopeReusableDraftFor() with PrepareEnvelopeForSignature::handle()
 * so both agree on what counts as "resumable".
 */
class GetDraftEnvelope
{
    public function handle(Document $document): ?Envelope
    {
        return Envelope::query()
            ->reusableDraftFor($document->id)
            ->with('signers')
            ->first();
    }
}
