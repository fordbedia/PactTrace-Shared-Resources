<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Workspace\Tests;

use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\WorkspacePresets;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceLabels;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTraceSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTraceSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTraceSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Creating a workspace of each type, and departing from the preset afterwards.
 *
 * The behaviour under test is the promise the presets make: they choose the
 * opening wording and then get out of the way. A preset that could not be
 * overridden would make the whole Workspace concept a fixed list of four
 * vocabularies rather than a starting point.
 */
class WorkspacePresetTest extends BaseTest
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function presets(): array
    {
        return [
            'legal' => [WorkspaceType::Legal->value, 'Your Attorney', 'Case'],
            'accounting' => [WorkspaceType::Accounting->value, 'Your Accountant', 'Engagement'],
            'consulting' => [WorkspaceType::Consulting->value, 'Your Consultant', 'Project'],
            'general' => [WorkspaceType::General->value, 'Your Provider', 'Project'],
        ];
    }

    #[DataProvider('presets')]
    public function test_creating_a_workspace_of_each_type_takes_that_types_labels(
        string $type,
        string $expectedClientLabel,
        string $expectedEngagementLabel,
    ): void {
        $tenant = ProviderTenantScenario::make('preset');

        $workspace = Workspace::factory()
            ->forProvider($tenant['provider'])
            ->ofType(WorkspaceType::from($type))
            ->create(['name' => "A {$type} workspace"]);

        // Asserted from a fresh read, not the in-memory instance, because the
        // labels are meant to be persisted at creation rather than derived on
        // access — that is what stops a later preset edit rewording workspaces
        // that already exist.
        $stored = Workspace::query()->findOrFail($workspace->id);

        $this->assertSame($expectedClientLabel, $stored->client_label);
        $this->assertSame($expectedEngagementLabel, $stored->engagement_label);
        $this->assertSame($type, $stored->workspace_type->value);
    }

    public function test_every_workspace_type_has_a_preset(): void
    {
        $presets = app(WorkspacePresets::class)->all();

        foreach (WorkspaceType::cases() as $type) {
            $this->assertArrayHasKey($type->value, $presets);
            $this->assertNotSame('', trim($presets[$type->value]->client));
            $this->assertNotSame('', trim($presets[$type->value]->engagement));
        }
    }

    public function test_a_workspace_defaults_to_the_general_type(): void
    {
        $tenant = ProviderTenantScenario::make('default-type');

        $workspace = Workspace::query()->create([
            'provider_id' => $tenant['provider']->id,
            'owner_id' => $tenant['owner']->id,
            'name' => 'Unspecified',
        ]);

        $this->assertSame(WorkspaceType::General, $workspace->workspace_type);
        $this->assertSame('Your Provider', $workspace->client_label);
    }

    public function test_both_labels_can_be_overridden_at_creation(): void
    {
        $tenant = ProviderTenantScenario::make('override');

        $workspace = Workspace::factory()
            ->forProvider($tenant['provider'])
            ->ofType(WorkspaceType::Legal)
            ->withLabels('Your Counsel', 'Matter')
            ->create(['name' => 'Custom wording']);

        $this->assertSame('Your Counsel', $workspace->client_label);
        $this->assertSame('Matter', $workspace->engagement_label);

        // The type is still legal — overriding the wording does not change what
        // kind of practice this is, so a settings screen can still offer "reset
        // to the legal defaults".
        $this->assertSame(WorkspaceType::Legal, $workspace->workspace_type);
    }

    public function test_one_label_can_be_overridden_while_the_other_keeps_the_preset(): void
    {
        $tenant = ProviderTenantScenario::make('partial');

        $workspace = Workspace::factory()
            ->forProvider($tenant['provider'])
            ->ofType(WorkspaceType::Legal)
            ->withLabels(engagement: 'Matter')
            ->create(['name' => 'Half custom']);

        $this->assertSame('Your Attorney', $workspace->client_label);
        $this->assertSame('Matter', $workspace->engagement_label);
    }

    public function test_labels_can_be_changed_after_creation(): void
    {
        $tenant = ProviderTenantScenario::make('rename');

        $workspace = Workspace::factory()
            ->forProvider($tenant['provider'])
            ->ofType(WorkspaceType::Accounting)
            ->create(['name' => 'Renameable']);

        $workspace->update([
            'client_label' => 'Your Bookkeeper',
            'engagement_label' => 'Job',
        ]);

        $reloaded = Workspace::query()->findOrFail($workspace->id);

        $this->assertSame('Your Bookkeeper', $reloaded->labels()->client);
        $this->assertSame('Job', $reloaded->labels()->engagement);
    }

    public function test_a_blank_label_falls_back_to_the_preset_rather_than_rendering_empty(): void
    {
        $tenant = ProviderTenantScenario::make('blank');

        $workspace = Workspace::factory()
            ->forProvider($tenant['provider'])
            ->ofType(WorkspaceType::Consulting)
            ->create(['name' => 'Blanked']);

        // Written past the model's guards, the way a bad import or a
        // hand-edited row would arrive.
        $workspace->forceFill(['client_label' => '   '])->save();

        $this->assertSame('Your Consultant', $workspace->fresh()->labels()->client);
    }

    public function test_applying_a_preset_discards_earlier_overrides(): void
    {
        $tenant = ProviderTenantScenario::make('reset');

        $workspace = Workspace::factory()
            ->forProvider($tenant['provider'])
            ->ofType(WorkspaceType::Legal)
            ->withLabels('Your Counsel', 'Matter')
            ->create(['name' => 'Resettable']);

        $workspace->applyPreset(WorkspaceType::Accounting)->save();

        $this->assertSame('Your Accountant', $workspace->client_label);
        $this->assertSame('Engagement', $workspace->engagement_label);
        $this->assertSame(WorkspaceType::Accounting, $workspace->fresh()->workspace_type);
    }

    public function test_presets_come_from_config_and_can_be_retuned_per_deployment(): void
    {
        config()->set('workspace.presets.legal', [
            'client' => 'Your Solicitor',
            'engagement' => 'Instruction',
        ]);

        $tenant = ProviderTenantScenario::make('config');

        $workspace = Workspace::factory()
            ->forProvider($tenant['provider'])
            ->ofType(WorkspaceType::Legal)
            ->create(['name' => 'Configured']);

        $this->assertSame('Your Solicitor', $workspace->client_label);
        $this->assertSame('Instruction', $workspace->engagement_label);
    }

    public function test_a_broken_preset_entry_degrades_to_the_general_preset(): void
    {
        // A config file edited into nonsense must not be able to produce a
        // workspace with no words to describe itself.
        config()->set('workspace.presets.legal', 'not an array');

        $labels = app(WorkspacePresets::class)->for(WorkspaceType::Legal);

        $this->assertSame('Your Provider', $labels->client);
        $this->assertSame('Project', $labels->engagement);
    }

    public function test_labels_reject_being_constructed_blank(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WorkspaceLabels('', 'Case');
    }
}
