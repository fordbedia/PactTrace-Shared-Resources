<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Services;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;

/**
 * Advances a single named milestone (see Domain\ValueObjects\DefaultMilestone)
 * from `pending` to `completed` in response to a real signal elsewhere in the
 * app, so `MatterProgressCalculator` — and therefore both the dashboard's
 * Progress column and the portal's timeline, see .claude/rules/matter.md —
 * moves off 0% as a matter actually progresses, instead of every milestone
 * sitting `pending` forever.
 *
 * Deliberately knows nothing about *why* a milestone advances — Document's
 * `UploadDocumentAction` and Signature's `RecordSignatureCompletionUseCase`
 * each own their own trigger condition and call `completeMilestone()` with
 * the matter id and milestone name once that condition is true. This keeps
 * the dependency direction one-way (Document/Signature -> Matter) instead of
 * Matter needing to know about `Envelope`/`Document` internals.
 *
 * "Engagement" needs no call here — `DefaultMilestoneSeeder` seeds it already
 * `completed` at matter-creation time. "Discovery" has no automatic signal in
 * the current data model and is intentionally never advanced by this service
 * — see .claude/rules/matter.md for that open gap (no staff UI exists yet to
 * advance a milestone by hand).
 *
 * When a milestone actually advances (a row was changed — not on a repeat
 * call that no-ops), the matter's assigned staff / owner is emailed via
 * MilestoneNotifier, gated on their `milestone_updated` preference. See
 * .claude/rules/notification.md, "Notification::isset() gating at dispatch
 * sites".
 */
class MilestoneProgressionService
{
    public function __construct(
        private readonly MilestoneNotifier $notifier,
    ) {
    }

    /**
     * No-op if the matter has no such milestone, or it's already past
     * `pending` — every caller is free to call this on every occurrence of
     * its own trigger event (e.g. every document upload) without tracking
     * "was this the first one" itself. Only a call that actually changes a
     * row fires the `milestone_updated` notification.
     */
    public function completeMilestone(?int $matterId, string $milestoneName): void
    {
        if ($matterId === null) {
            return;
        }

        $advanced = Milestone::query()
            ->where('matter_id', $matterId)
            ->where('name', $milestoneName)
            ->where('status', 'pending')
            ->update(['status' => 'completed', 'completed_at' => now()]);

        if ($advanced === 0) {
            return;
        }

        // acrossWorkspaces(): this runs from webhook / queue context (no
        // workspace to resolve) as well as HTTP, and the raw milestone update
        // above is already workspace-agnostic — so resolving the matter for
        // the notification must be too. See .claude/rules/workspace.md.
        $matter = Matter::query()->acrossWorkspaces()->find($matterId);

        if ($matter !== null) {
            $this->notifier->milestoneCompleted($matter, $milestoneName);
        }
    }
}
