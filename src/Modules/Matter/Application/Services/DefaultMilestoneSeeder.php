<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Services;

use PactTrackSDK\SharedResources\Modules\Matter\Domain\ValueObjects\DefaultMilestone;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * Seeds a freshly created Matter with the default milestone set
 * (Domain\ValueObjects\DefaultMilestone::ordered()) so the client portal's
 * Matter Progress timeline (`/portal/matter/{matterId}`,
 * .claude/rules/matter.md) has something to render. Called from
 * CreateMattersHandler on create only — never on update, since re-seeding
 * an existing Matter's milestones would silently discard whatever
 * provider-side progress had already been recorded against them.
 *
 * `seed()` is itself idempotent (a no-op once the Matter already has any
 * milestones) so a caller never has to track "did I already seed this one"
 * separately — the guard lives here, not in the caller.
 *
 * `Engagement` is seeded already `completed` — a Matter existing at all
 * means the client is engaged, so there is no later signal to wait for (see
 * Application\Services\MilestoneProgressionService, which handles the
 * remaining milestones that *do* have a later trigger). Every other
 * milestone starts `pending`.
 */
class DefaultMilestoneSeeder
{
    public function seed(Matter $matter): void
    {
        if ($matter->milestones()->exists()) {
            return;
        }

        foreach (DefaultMilestone::ordered() as $milestone) {
            $isEngagement = $milestone->name === DefaultMilestone::ENGAGEMENT;

            $matter->milestones()->create([
                'name' => $milestone->name,
                'status' => $isEngagement ? 'completed' : 'pending',
                'position' => $milestone->position,
                'completed_at' => $isEngagement ? now() : null,
            ]);
        }
    }
}
