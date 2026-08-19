<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * `workspace_id` gets filled in on create without the caller passing it.
 *
 * This is what makes the denormalised column tolerable. Three tables carrying
 * the same fact will drift the moment any write path can forget one of them, so
 * no write path is allowed to: a matter takes the current workspace, and a
 * document and an envelope take their parent's, which cannot disagree with it.
 */
class WorkspaceAssignmentTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('assign');
        $this->workspace = $this->tenant['workspace'];

        app(CurrentWorkspace::class)->setId($this->workspace->id);
    }

    public function test_a_matter_takes_the_current_workspace(): void
    {
        $matter = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        $this->assertSame($this->workspace->id, (int) $matter->workspace_id);
    }

    public function test_a_document_inherits_the_workspace_of_its_matter(): void
    {
        $otherWorkspace = Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'assign other']);

        $matter = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $otherWorkspace->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        // Created while standing in a *different* workspace: the parent wins
        // over the ambient context, which is what keeps the two columns
        // consistent with each other.
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $matter->id,
            'uploaded_by' => $this->tenant['owner']->id,
        ]);

        $this->assertSame($otherWorkspace->id, (int) $document->workspace_id);
    }

    public function test_an_envelope_inherits_the_workspace_of_its_document(): void
    {
        $otherWorkspace = Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'assign envelope']);

        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $otherWorkspace->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => null,
            'uploaded_by' => $this->tenant['owner']->id,
        ]);

        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
        ]);

        $this->assertSame($otherWorkspace->id, (int) $envelope->workspace_id);
    }

    public function test_a_document_with_no_matter_falls_back_to_the_current_workspace(): void
    {
        // The one-off signature request from the Client module's rules: a
        // document filed with no matter at all still belongs to a workspace.
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => null,
            'uploaded_by' => $this->tenant['owner']->id,
        ]);

        $this->assertSame($this->workspace->id, (int) $document->workspace_id);
    }

    public function test_an_explicit_workspace_is_never_overwritten(): void
    {
        $otherWorkspace = Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'assign explicit']);

        $matter = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $otherWorkspace->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        $this->assertSame($otherWorkspace->id, (int) $matter->workspace_id);
    }

    public function test_with_no_workspace_context_the_column_is_left_null(): void
    {
        app(CurrentWorkspace::class)->setId(null);

        $matter = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        // Null rather than a guessed workspace. A row like this is the same
        // shape as the pre-existing rows the migration could not backfill, and
        // it is invisible to any workspace-scoped query.
        $this->assertNull($matter->workspace_id);

        app(CurrentWorkspace::class)->setId($this->workspace->id);
        $this->assertSame(0, Matter::query()->whereKey($matter->id)->count());
    }

    public function test_the_scope_hides_records_of_a_soft_deleted_workspace(): void
    {
        // Soft-deleting a workspace does not fire the database's ON DELETE
        // CASCADE, so its documents remain in the table. They must stop being
        // reachable all the same — via the workspace context, which can no
        // longer resolve to a deleted workspace's id through the resolver.
        $this->workspace->delete();

        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(1, Workspace::withTrashed()->count());

        // The rows themselves survive for restoration.
        $this->assertGreaterThan(
            0,
            Document::query()->acrossWorkspaces()->where('workspace_id', $this->workspace->id)->count(),
        );
    }
}
