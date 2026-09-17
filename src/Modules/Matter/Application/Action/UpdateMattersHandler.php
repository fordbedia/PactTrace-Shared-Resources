<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Action;

use Illuminate\Support\Facades\DB;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Services\MilestoneNotifier;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * Applies an edit to an existing matter — the Matter Detail page's
 * assign/reassign-staff control, inline status edit, and any future field
 * edit. Deliberately does NOT seed default milestones (that is
 * CreateMattersHandler's job, guarded on `wasRecentlyCreated`) — re-seeding on
 * every edit would discard recorded milestone progress. See
 * .claude/rules/matter.md.
 *
 * A change to the matter's `status` is one of the two `milestone_updated`
 * notification triggers (the other is automatic milestone completion in
 * MilestoneProgressionService); both go through MilestoneNotifier, gated on
 * the recipient's preference. See .claude/rules/notification.md.
 *
 * A change to the matter's `client_id` is the matter's own single source of
 * truth for "which client owns every document filed under it" — the matter
 * update and the cascade onto its documents run in one DB transaction, so a
 * document can never observably disagree with its matter's current client
 * (see .claude/rules/document.md, "Documents on this matter", and
 * .claude/rules/matter.md, "Matter Type and Edit Matter"). Envelopes are
 * deliberately left untouched here — an envelope snapshots its recipient at
 * `PrepareEnvelopeForSignature::handle()` time and must keep referring to
 * whoever it was actually sent to; only a *document not yet sent* needs to
 * track the matter's client live, and the next envelope prepared for it will
 * correctly read the now-corrected `documents.client_id`.
 */
class UpdateMattersHandler
{
	public function __construct(
		private readonly MattersRepository $repository,
		private readonly MilestoneNotifier $milestoneNotifier,
		private readonly DocumentRepository $documents,
	)
	{}

	public function handle(Matter $matter, MattersData $data): Matter
	{
		$previousStatus = (string) $matter->status;
		$previousClientId = $matter->client_id;

		$updated = DB::transaction(function () use ($matter, $data, $previousClientId) {
			$updated = $this->repository->updateMatter($matter, $data);

			if ($updated->client_id !== $previousClientId) {
				$this->documents->reassignClientForMatter($updated->id, $updated->client_id);
			}

			return $updated;
		});

		if ($previousStatus !== (string) $updated->status) {
			$this->milestoneNotifier->matterStatusChanged($updated, $previousStatus);
		}

		return $updated;
	}
}
