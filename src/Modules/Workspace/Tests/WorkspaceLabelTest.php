<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Tests;

use Illuminate\Support\Facades\Blade;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Labels\WorkspaceLabelResolver;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;

/**
 * The read path for wording: workspace_label() and the @workspaceLabel Blade
 * directive.
 *
 * This is what requirement 7 replaces hardcoded "Attorney" copy with. Nothing
 * in the backend renders these yet — there are no application controllers or
 * views — so these tests are the only current consumer, and they pin the
 * contract that a portal view or an API resource will rely on.
 */
class WorkspaceLabelTest extends BaseTest
{
    private function enterWorkspaceOfType(WorkspaceType $type, string $name): Workspace
    {
        $tenant = ProviderTenantScenario::make('label-'.$name);

        $workspace = Workspace::factory()
            ->forProvider($tenant['provider'])
            ->ofType($type)
            ->create(['name' => $name]);

        app(CurrentWorkspace::class)->setId($workspace->id);
        app(WorkspaceLabelResolver::class)->flush();

        return $workspace;
    }

    public function test_the_helper_returns_the_current_workspaces_labels(): void
    {
        $this->enterWorkspaceOfType(WorkspaceType::Legal, 'legal');

        $this->assertSame('Your Attorney', workspace_label('client'));
        $this->assertSame('Case', workspace_label('engagement'));
    }

    public function test_the_helper_follows_a_workspace_switch(): void
    {
        $this->enterWorkspaceOfType(WorkspaceType::Legal, 'first');
        $this->assertSame('Your Attorney', workspace_label('client'));

        $this->enterWorkspaceOfType(WorkspaceType::Accounting, 'second');
        $this->assertSame('Your Accountant', workspace_label('client'));
    }

    public function test_the_helper_returns_custom_labels_over_the_preset(): void
    {
        $workspace = $this->enterWorkspaceOfType(WorkspaceType::Legal, 'custom');

        $workspace->update(['client_label' => 'Your Counsel']);
        app(WorkspaceLabelResolver::class)->flush();

        $this->assertSame('Your Counsel', workspace_label('client'));
        $this->assertSame('Case', workspace_label('engagement'));
    }

    public function test_with_no_workspace_the_helper_falls_back_to_neutral_wording(): void
    {
        app(CurrentWorkspace::class)->setId(null);
        app(WorkspaceLabelResolver::class)->flush();

        // A signed-out page still has headings to render, so this must produce
        // a usable noun rather than an empty string or an exception.
        $this->assertSame('Your Provider', workspace_label('client'));
        $this->assertSame('Project', workspace_label('engagement'));
    }

    public function test_both_labels_can_be_read_at_once(): void
    {
        $this->enterWorkspaceOfType(WorkspaceType::Consulting, 'pair');

        $this->assertSame(
            ['client' => 'Your Consultant', 'engagement' => 'Project'],
            workspace_labels(),
        );
    }

    public function test_an_unknown_label_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        workspace_label('nonsense');
    }

    public function test_the_blade_directive_renders_the_label(): void
    {
        $this->enterWorkspaceOfType(WorkspaceType::Legal, 'blade');

        $rendered = Blade::render("@workspaceLabel('client') — @workspaceLabel('engagement')");

        $this->assertSame('Your Attorney — Case', $rendered);
    }

    public function test_the_blade_directive_escapes_provider_supplied_wording(): void
    {
        // Labels are free text typed by a provider, so they reach the directive
        // as untrusted input and must not be able to inject markup.
        $workspace = $this->enterWorkspaceOfType(WorkspaceType::General, 'escaping');

        $workspace->update(['client_label' => '<script>alert(1)</script>']);
        app(WorkspaceLabelResolver::class)->flush();

        $rendered = Blade::render("@workspaceLabel('client')");

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }
}
