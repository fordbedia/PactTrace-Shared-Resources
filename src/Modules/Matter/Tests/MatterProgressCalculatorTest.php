<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterProgressCalculator;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;

/**
 * Backs both the dashboard's Progress column and the portal timeline (see
 * .claude/rules/matter.md) — this covers the "stuck at 0%" bug: with real
 * milestone progression wired up (MilestoneProgressionService), a matter
 * partway through its lifecycle must report something other than 0% or
 * 100%.
 */
class MatterProgressCalculatorTest extends BaseTest
{
    public function test_calculates_a_non_zero_non_complete_percentage_for_a_matter_partway_through_its_milestones(): void
    {
        $tenant = ProviderTenantScenario::make('progress-calc');
        $matter = $tenant['matter'];

        // The scenario's own auto-created milestone is a random-named
        // fixture row unrelated to the default set — replace it with the
        // same five-milestone shape DefaultMilestoneSeeder produces, two of
        // them completed.
        $matter->milestones()->delete();
        foreach (['Engagement', 'Discovery', 'Drafting', 'Review', 'Completed'] as $position => $name) {
            Milestone::factory()->create([
                'matter_id' => $matter->id,
                'name' => $name,
                'position' => $position,
                'status' => in_array($name, ['Engagement', 'Drafting'], true) ? 'completed' : 'pending',
                'completed_at' => in_array($name, ['Engagement', 'Drafting'], true) ? now() : null,
            ]);
        }

        $percentage = app(MatterProgressCalculator::class)->calculate($matter->fresh());

        $this->assertSame(40, $percentage);
        $this->assertNotSame(0, $percentage);
        $this->assertNotSame(100, $percentage);
    }

    public function test_a_matter_with_no_milestones_falls_back_to_zero_or_hundred_by_status(): void
    {
        $tenant = ProviderTenantScenario::make('progress-calc-empty');
        $matter = $tenant['matter'];
        $matter->milestones()->delete();

        $calculator = app(MatterProgressCalculator::class);

        $matter->forceFill(['status' => 'active'])->save();
        $this->assertSame(0, $calculator->calculate($matter->fresh()));

        $matter->forceFill(['status' => 'completed'])->save();
        $this->assertSame(100, $calculator->calculate($matter->fresh()));
    }
}
