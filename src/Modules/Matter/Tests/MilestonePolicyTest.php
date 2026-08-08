<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Matter\Tests;

use PactTraceSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTraceSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTraceSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Milestones are the one model whose tenancy is indirect — the row has no
 * `provider_id` or `client_id`, only a `matter_id` — so it gets its own file.
 * Everything here is really testing that the inherited scoping resolves through
 * the matter instead of silently reading nulls and either denying everyone or,
 * far worse, comparing null to null and letting everyone through.
 */
class MilestonePolicyTest extends BaseTest
{
    private TestScenarioCollection $tenantA;

    private TestScenarioCollection $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = ProviderTenantScenario::make('ms-a');
        $this->tenantB = ProviderTenantScenario::make('ms-b');
    }

    public function test_scoping_is_inherited_from_the_parent_matter(): void
    {
        $this->assertTrue($this->tenantA['owner']->can('view', $this->tenantA['milestone']));
        $this->assertTrue($this->tenantA['staff']->can('update', $this->tenantA['milestone']));

        $this->assertFalse($this->tenantB['owner']->can('view', $this->tenantA['milestone']));
        $this->assertFalse($this->tenantB['staff']->can('update', $this->tenantA['milestone']));
    }

    public function test_a_client_only_reaches_milestones_on_their_own_matter(): void
    {
        $clientUser = $this->tenantA['clientUser'];

        $ownMilestone = $this->tenantA['milestone'];
        $siblingMilestone = Milestone::factory()->create([
            'matter_id' => $this->tenantA['otherMatter']->id,
        ]);

        $this->assertTrue($clientUser->can('view', $ownMilestone));
        $this->assertFalse($clientUser->can('view', $siblingMilestone));

        // Read-only either way.
        $this->assertFalse($clientUser->can('update', $ownMilestone));
        $this->assertFalse($clientUser->can('delete', $ownMilestone));
    }

    public function test_creating_a_milestone_is_scoped_by_the_target_matter(): void
    {
        // `create` receives a Matter rather than a Milestone. The policy has to
        // notice and read the matter's own columns instead of trying to walk a
        // `matter` relation that a Matter does not have.
        $owner = $this->tenantA['owner'];

        $this->assertTrue($owner->can('create', [Milestone::class, $this->tenantA['matter']]));
        $this->assertFalse($owner->can('create', [Milestone::class, $this->tenantB['matter']]));
    }

    public function test_a_milestone_whose_matter_is_missing_is_denied(): void
    {
        // Fails closed: an unsaved milestone pointing at nothing resolves to a
        // null provider, which must not match a null on the actor's side either.
        $orphan = new Milestone(['matter_id' => null]);

        $this->assertFalse($this->tenantA['owner']->can('view', $orphan));
        $this->assertFalse($this->tenantA['owner']->can('update', $orphan));
    }

    public function test_matter_gates_still_apply_normally(): void
    {
        $this->assertTrue($this->tenantA['owner']->can('delete', $this->tenantA['matter']));
        $this->assertFalse($this->tenantA['staff']->can('delete', $this->tenantA['matter']));
        $this->assertFalse($this->tenantB['owner']->can('delete', $this->tenantA['matter']));

        $this->assertTrue($this->tenantA['staff']->can('create', [Matter::class, $this->tenantA['client']]));
        $this->assertFalse($this->tenantA['clientUser']->can('create', [Matter::class, $this->tenantA['client']]));
    }
}
