<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeNotFoundForMatterException;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;

/**
 * Resolves which envelope the envelope detail view
 * (/dashboard/signatures/matter/{matterId}, see .claude/rules/signature.md)
 * should render for a given matter, and eager-loads everything
 * EnvelopeDetailResource and MatterActivityFeedBuilder::buildForEnvelope()
 * need in the one round trip PactTrack's other detail views already assume
 * (same shape as GetMatterPortalDetailHandler, see .claude/rules/matter.md).
 *
 * A Matter hasMany Document, and a Document can itself accumulate more than
 * one Envelope over its lifetime (voided, then re-prepared) — so a matter's
 * envelopes are not 1:1 with its documents, let alone with the matter
 * itself. Resolution:
 *
 *   - `$envelopePublicId` given: the one envelope on this matter with that
 *     public_id. This is what every "View Signature" link on the Matter
 *     Detail page's "Documents on this matter" section actually sends, so
 *     this is the normal path once a matter has more than one document.
 *   - `$envelopePublicId` omitted, exactly one envelope exists on the
 *     matter: that one — the bare
 *     /dashboard/signatures/matter/{matterId} URL (no disambiguator) is only
 *     ever unambiguous in this case.
 *   - `$envelopePublicId` omitted, more than one envelope exists: the most
 *     recently created one. Not a real product decision so much as a safe
 *     default for a URL that should have carried a query param — the
 *     "Documents on this matter" section never links to the bare route in
 *     this situation, only ever with an explicit envelope id.
 *   - No envelope exists on the matter at all: EnvelopeNotFoundForMatterException.
 */
class GetMatterEnvelopeDetail
{
    public function handle(Matter $matter, ?string $envelopePublicId): Envelope
    {
        $query = Envelope::query()
            ->whereHas('document', fn ($query) => $query->where('matter_id', $matter->id))
            ->with([
                'document.matter',
                'document.uploader',
                'client',
                'provider',
                'signers',
            ]);

        if ($envelopePublicId !== null) {
            $envelope = $query->where('public_id', $envelopePublicId)->first();
        } else {
            $envelope = $query->latest('created_at')->first();
        }

        if ($envelope === null) {
            throw new EnvelopeNotFoundForMatterException($matter->id, $envelopePublicId);
        }

        return $envelope;
    }
}
