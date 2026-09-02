<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Tests;

use PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases\UpdateWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Covers the type-immutability rule (build prompt Part 2): a workspace type,
 * once specialised, never changes through an edit. The one exception is the
 * first move off the `general` placeholder RegisterProvider stamps at sign-up,
 * which the onboarding screen makes.
 */
class UpdateWorkspaceTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('update-ws');
    }

    private function useCase(): UpdateWorkspace
    {
        return $this->app->make(UpdateWorkspace::class);
    }

    public function test_it_updates_name_and_labels(): void
    {
        $workspace = Workspace::factory()->forProvider($this->tenant['provider'])
            ->ofType(WorkspaceType::Legal)->create();

        $updated = $this->useCase()->handle($workspace, 'Renamed', null, 'Counsel', 'Case File');

        $this->assertSame('Renamed', $updated->name);
        $this->assertSame('Counsel', $updated->client_label);
        $this->assertSame('Case File', $updated->engagement_label);
        $this->assertSame(WorkspaceType::Legal, $updated->workspace_type);
    }

    public function test_a_general_workspace_may_choose_a_type_once(): void
    {
        $workspace = Workspace::factory()->forProvider($this->tenant['provider'])
            ->ofType(WorkspaceType::General)->create();

        $updated = $this->useCase()->handle($workspace, 'Practice', 'legal', null, null);

        $this->assertSame(WorkspaceType::Legal, $updated->workspace_type);
        // Blank labels refilled from the newly chosen type's preset.
        $this->assertSame('Your Attorney', $updated->client_label);
        $this->assertSame('Case', $updated->engagement_label);
    }

    public function test_an_already_specialised_workspace_ignores_a_type_change(): void
    {
        $workspace = Workspace::factory()->forProvider($this->tenant['provider'])
            ->ofType(WorkspaceType::Legal)->create();

        $updated = $this->useCase()->handle($workspace, 'Still Legal', 'accounting', null, null);

        $this->assertSame(WorkspaceType::Legal, $updated->workspace_type);
    }

    public function test_a_general_workspace_cannot_be_pinned_back_to_general_as_a_type_change(): void
    {
        // Nonsensical no-op, but proves "general -> general" isn't treated as a
        // move that then locks anything oddly.
        $workspace = Workspace::factory()->forProvider($this->tenant['provider'])
            ->ofType(WorkspaceType::General)->create();

        $updated = $this->useCase()->handle($workspace, 'General', 'general', null, null);

        $this->assertSame(WorkspaceType::General, $updated->workspace_type);
    }
}
