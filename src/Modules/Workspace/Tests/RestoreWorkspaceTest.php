<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Tests;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\RestoreWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

class RestoreWorkspaceTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('restore-ws');
    }

    private function actor(): User
    {
        return $this->tenant['owner'];
    }

    private function useCase(): RestoreWorkspace
    {
        return $this->app->make(RestoreWorkspace::class);
    }

    public function test_it_restores_a_soft_deleted_workspace_and_audits_it(): void
    {
        $workspace = Workspace::factory()->forProvider($this->tenant['provider'])->create();
        $workspace->delete();
        $this->assertSoftDeleted('workspaces', ['id' => $workspace->id]);

        $restored = $this->useCase()->handle(
            $this->actor(),
            Workspace::withTrashed()->findOrFail($workspace->id),
        );

        $this->assertFalse($restored->trashed());
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->actor()->id,
            'action' => 'workspace.reactivated',
            'auditable_id' => $workspace->id,
        ]);
    }

    public function test_it_is_a_noop_on_an_already_active_workspace(): void
    {
        $workspace = Workspace::factory()->forProvider($this->tenant['provider'])->create();

        $result = $this->useCase()->handle($this->actor(), $workspace);

        $this->assertFalse($result->trashed());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'workspace.reactivated',
            'auditable_id' => $workspace->id,
        ]);
    }
}
