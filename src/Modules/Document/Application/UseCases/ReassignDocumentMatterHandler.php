<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The Documents page's per-row "Reassign Matter" action (the pen icon) — see
 * .claude/rules/document.md, "Reassign Matter from the Documents page".
 * Changes exactly which Matter a document is filed under; it never touches
 * the Matter entity's own fields (name/status/dates/type stay exclusively
 * editable from the Matter module itself).
 *
 * Reassigning onto a real Matter derives `client_id` (and `workspace_id`)
 * from that Matter, the same "never trust an independently-submitted value
 * once a parent is present" rule DocumentController::store() already
 * applies at upload time — a document must never end up with a `matter_id`
 * and `client_id` that disagree. Reassigning to no Matter at all (unfiling)
 * leaves both untouched: there is no new parent to derive from, and
 * discarding a document's existing client/workspace just because its matter
 * was cleared would be needlessly destructive — "client, no matter" is
 * already a real, supported case elsewhere in this module.
 */
class ReassignDocumentMatterHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {
    }

    public function handle(Document $document, ?Matter $matter, User $actor): Document
    {
        $previousMatterId = $document->matter_id;
        $previousClientId = $document->client_id;

        $attributes = ['matter_id' => $matter?->id];

        if ($matter !== null) {
            $attributes['client_id'] = $matter->client_id;
            // The matter may live in a different workspace than the one the
            // document was originally created in — resync so the document
            // doesn't go invisible under WorkspaceScope from its new
            // matter's own workspace. See .claude/rules/workspace.md,
            // BelongsToWorkspace.
            $attributes['workspace_id'] = $matter->workspace_id;
        }

        $document = $this->documents->save($document, $attributes);

        AuditLog::create([
            'provider_id' => $document->provider_id,
            'user_id' => $actor->id,
            'action' => 'document.matter_reassigned',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
            'metadata' => [
                'previous_matter_id' => $previousMatterId,
                'new_matter_id' => $matter?->id,
                'previous_client_id' => $previousClientId,
                'new_client_id' => $document->client_id,
            ],
        ]);

        return $document;
    }
}
