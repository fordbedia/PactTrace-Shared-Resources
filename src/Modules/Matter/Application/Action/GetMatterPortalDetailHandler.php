<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Action;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * Loads everything `PortalMatterResource` needs to render the client
 * portal's matter detail view (`/portal/matter/{matterId}`, see
 * .claude/rules/matter.md) in the one round trip PactTrack's other
 * `*Resource`s already assume — mirrors `MattersController::show()`'s
 * `$matter->load([...])`, just with the wider relation set a portal page
 * needs (documents, their envelopes and signers, milestones, the provider).
 *
 * Authorization (`Gate::authorize('view', $matter)`) is the caller's job —
 * this class only assembles data for a `$matter` already confirmed visible
 * to the acting client.
 */
class GetMatterPortalDetailHandler
{
    public function handle(Matter $matter): Matter
    {
        return $matter->load([
            'client',
            'provider',
            'milestones' => fn ($query) => $query->orderBy('position'),
            /**
             * Drafts are filtered out here rather than left for the
             * frontend: a draft document hasn't been sent to this client
             * yet (see .claude/rules/document.md), so it isn't this
             * matter's client's to see. This is the one place in the
             * feature spec flagged as a genuine product ambiguity rather
             * than an obvious mapping — see PortalMatterController's own
             * note, and the report back to Ed.
             */
            'documents' => fn ($query) => $query
                ->whereNot('status', DocumentStatus::Draft->value)
                ->latest(),
            'documents.uploader',
            'documents.envelopes',
            'documents.envelopes.signers',
        ]);
    }
}
