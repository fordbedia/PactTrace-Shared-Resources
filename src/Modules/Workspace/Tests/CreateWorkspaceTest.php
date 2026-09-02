<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Tests;

use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\CreateWorkspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The CreateWorkspace use case in isolation. The permission/tenant gate is the
 * controller's job (WorkspaceControllerTest covers the 403 for staff) — this
 * only checks that a row is written with the given scope and that blank labels
 * are filled from the type's preset by the model hook.
 */
class CreateWorkspaceTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('create-ws');
    }

    private function useCase(): CreateWorkspace
    {
        return $this->app->make(CreateWorkspace::class);
    }

    public function test_it_creates_a_workspace_scoped_to_the_given_provider_and_owner(): void
    {
        $workspace = $this->useCase()->handle(
            providerId: $this->tenant['provider']->id,
            ownerId: $this->tenant['owner']->id,
            name: 'Consulting Arm',
            workspaceType: 'consulting',
        );

        $this->assertSame($this->tenant['provider']->id, (int) $workspace->provider_id);
        $this->assertSame($this->tenant['owner']->id, (int) $workspace->owner_id);
        $this->assertSame('consulting', $workspace->workspace_type->value);
        // Preset for `consulting`, filled by Workspace::creating().
        $this->assertSame('Your Consultant', $workspace->client_label);
        $this->assertSame('Project', $workspace->engagement_label);

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'provider_id' => $this->tenant['provider']->id,
            'name' => 'Consulting Arm',
        ]);
    }

    public function test_an_explicit_label_overrides_the_preset(): void
    {
        $workspace = $this->useCase()->handle(
            providerId: $this->tenant['provider']->id,
            ownerId: $this->tenant['owner']->id,
            name: 'Custom Labels',
            workspaceType: 'legal',
            clientLabel: 'Represented Party',
            engagementLabel: null,
        );

        $this->assertSame('Represented Party', $workspace->client_label);
        // The one left null still takes the legal preset.
        $this->assertSame('Case', $workspace->engagement_label);
    }
}
