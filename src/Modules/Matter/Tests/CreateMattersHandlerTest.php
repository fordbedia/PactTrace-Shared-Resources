<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use PactTrackSDK\SharedResources\Modules\Matter\Application\Action\CreateMattersHandler;
use PactTrackSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;

/**
 * Covers the client portal's missing Matter Progress timeline fix — see
 * .claude/rules/matter.md. A freshly created Matter previously had zero
 * milestones (CreateMattersHandler just delegated straight to
 * `upsert()`), so `MatterDetailView`'s `matter.milestones.length > 0` guard
 * never rendered the tracker card. `Application\Services\DefaultMilestoneSeeder`
 * is what now fixes that, wired in here.
 */
class CreateMattersHandlerTest extends BaseTest
{
    private function dataFor(int $providerId, int $workspaceId, int $clientId, ?int $id = null): MattersData
    {
        return new MattersData(
            id: $id,
            provider_id: $providerId,
            workspace_id: $workspaceId,
            client_id: $clientId,
            name: 'Estate Plan',
            description: null,
            status: 'active',
            start_date: null,
            due_date: null,
        );
    }

    public function test_creating_a_matter_seeds_the_default_milestone_set_in_order(): void
    {
        $tenant = ProviderTenantScenario::make('create-matter');

        $handler = app(CreateMattersHandler::class);
        $matter = $handler->handle($this->dataFor(
            $tenant['provider']->id,
            $tenant['workspace']->id,
            $tenant['client']->id,
        ));

        $milestones = $matter->milestones()->orderBy('position')->get();

        $this->assertSame(
            ['Engagement', 'Discovery', 'Drafting', 'Review', 'Completed'],
            $milestones->pluck('name')->all(),
        );
        $this->assertSame('Completed', $milestones->last()->name);
        $this->assertTrue($milestones->every(fn ($milestone) => $milestone->status === 'pending'));
    }

    public function test_updating_an_existing_matter_does_not_reseed_or_duplicate_milestones(): void
    {
        $tenant = ProviderTenantScenario::make('update-matter');
        $handler = app(CreateMattersHandler::class);

        $matter = $handler->handle($this->dataFor(
            $tenant['provider']->id,
            $tenant['workspace']->id,
            $tenant['client']->id,
        ));

        $firstMilestone = $matter->milestones()->orderBy('position')->first();
        $firstMilestone->update(['status' => 'completed']);

        // Same MattersData, but with the id set — upsert() resolves this as
        // an update (updateOrCreate), never a second create.
        $updated = $handler->handle($this->dataFor(
            $tenant['provider']->id,
            $tenant['workspace']->id,
            $tenant['client']->id,
            $matter->id,
        ));

        $this->assertSame($matter->id, $updated->id);
        $this->assertFalse($updated->wasRecentlyCreated);

        $milestones = $updated->milestones()->get();
        $this->assertCount(5, $milestones);
        $this->assertSame('completed', $milestones->firstWhere('id', $firstMilestone->id)->status);
    }
}
