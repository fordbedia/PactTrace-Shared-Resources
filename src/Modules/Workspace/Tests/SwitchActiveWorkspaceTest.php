<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Tests;

use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\SwitchActiveWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * SwitchActiveWorkspace writes the choice in three places so it survives both
 * the rest of this request and the next sign-in. The controller has already
 * done the 404/authorisation — this test only checks the three writes.
 */
class SwitchActiveWorkspaceTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('switch-ws');
    }

    public function test_it_writes_session_user_column_and_the_request_context(): void
    {
        $target = Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'switch target']);

        $this->app->make(SwitchActiveWorkspace::class)
            ->handle($this->tenant['owner'], $target);

        // 1. session
        $this->assertSame($target->id, session('workspace_id'));
        // 2. users.default_workspace_id
        $this->assertSame($target->id, (int) $this->tenant['owner']->fresh()->default_workspace_id);
        // 3. the pinned request context
        $this->assertSame($target->id, app(CurrentWorkspace::class)->id());
    }
}
