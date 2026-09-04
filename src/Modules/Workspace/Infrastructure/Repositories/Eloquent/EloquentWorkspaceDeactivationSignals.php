<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceDeactivationSignalReader;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceDeactivationSignals;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes\WorkspaceScope;

/**
 * Counts, in SQL, the live activity that blocks deactivating one workspace.
 *
 * Every query drops the `WorkspaceScope` global scope and filters
 * `workspace_id` explicitly. The scope narrows to whatever workspace the
 * request happens to carry as context, and the `{workspace}` route parameter
 * makes that the *target* workspace here anyway — but relying on that
 * coincidence would be fragile, and a console/queue caller would carry no
 * context at all. SoftDeletes' own scope is left on, so trashed documents
 * don't count.
 *
 * The "matter completed" bar excludes `cancelled` as well as `completed`: a
 * cancelled matter is closed work, not activity the provider still has to wind
 * down before deactivating.
 *
 * Unaccepted client invitations are no longer read here — they stopped being a
 * blocker; `WorkspaceInvitationCanceller` expires them as a side effect of the
 * deactivation instead.
 */
final class EloquentWorkspaceDeactivationSignals implements WorkspaceDeactivationSignalReader
{
    /** Matter statuses that still count as "open work". */
    private const OPEN_MATTER_STATUSES = ['active', 'on_hold'];

    public function read(int $workspaceId): WorkspaceDeactivationSignals
    {
        $openMatters = Matter::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', self::OPEN_MATTER_STATUSES)
            ->count();

        $pendingDocuments = Document::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', [
                DocumentStatus::Sent->value,
                DocumentStatus::PartiallySigned->value,
            ])
            ->count();

        $pendingEnvelopes = Envelope::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereNotIn('status', [
                EnvelopeStatus::Completed->value,
                EnvelopeStatus::Declined->value,
                EnvelopeStatus::Voided->value,
                EnvelopeStatus::Expired->value,
            ])
            ->count();

        return new WorkspaceDeactivationSignals(
            $openMatters,
            $pendingDocuments,
            $pendingEnvelopes,
        );
    }
}
