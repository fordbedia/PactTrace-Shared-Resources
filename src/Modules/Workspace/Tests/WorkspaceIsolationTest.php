<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The point of denormalising `workspace_id` onto three tables: each one can be
 * queried in isolation, and a query made inside one workspace must not see
 * another's rows.
 *
 * Both workspaces here belong to the *same provider*. That is deliberate and is
 * the case worth testing — cross-provider isolation is already covered by
 * TenantIsolationTest through the policies, and would pass here even if the
 * workspace scope did nothing at all. Two workspaces under one provider is the
 * only arrangement where this scope is the sole thing standing between them.
 */
class WorkspaceIsolationTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    private Workspace $legal;

    private Workspace $consulting;

    private Document $consultingDocument;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('iso');

        $this->legal = $this->tenant['workspace'];

        $this->consulting = Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'iso consulting']);

        // A full matter -> document -> envelope chain in the second workspace,
        // mirroring what the scenario built in the first.
        $matter = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->consulting->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        $this->consultingDocument = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->consulting->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $matter->id,
            'uploaded_by' => $this->tenant['owner']->id,
        ]);

        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->consulting->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->consultingDocument->id,
        ]);

        // MessageThread and AuditLog carry workspace_id too (added later, same
        // shape). One row of each in every workspace so the data-provider
        // tests below can treat them like the original three.
        MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['staff'])
            ->create(['workspace_id' => $this->legal->id]);

        MessageThread::factory()
            ->forMatter($matter, $this->tenant['staff'])
            ->create(['workspace_id' => $this->consulting->id]);

        AuditLog::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['workspace_id' => $this->legal->id]);

        AuditLog::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['workspace_id' => $this->consulting->id]);
    }

    private function enterWorkspace(Workspace $workspace): void
    {
        app(CurrentWorkspace::class)->setId($workspace->id);
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function scopedModels(): array
    {
        return [
            'matter' => [Matter::class],
            'document' => [Document::class],
            'envelope' => [Envelope::class],
            'messageThread' => [MessageThread::class],
            'auditLog' => [AuditLog::class],
        ];
    }

    #[DataProvider('scopedModels')]
    public function test_a_query_only_returns_records_of_the_current_workspace(string $model): void
    {
        $this->enterWorkspace($this->legal);

        $ids = $model::query()->pluck('workspace_id')->unique();

        $this->assertCount(1, $ids, "{$model} returned rows from more than one workspace.");
        $this->assertSame($this->legal->id, (int) $ids->first());
    }

    #[DataProvider('scopedModels')]
    public function test_a_record_of_another_workspace_cannot_be_found_by_id(string $model): void
    {
        // Grab the other workspace's row while standing in that workspace...
        $this->enterWorkspace($this->consulting);
        $foreignId = $model::query()->firstOrFail()->getKey();

        // ...then look for it from the first. A cross-workspace query returns
        // nothing: not a filtered-down record, nothing at all.
        $this->enterWorkspace($this->legal);

        $this->assertNull($model::query()->find($foreignId));
        $this->assertSame(0, $model::query()->whereKey($foreignId)->count());
    }

    #[DataProvider('scopedModels')]
    public function test_counts_and_aggregates_are_scoped_too(string $model): void
    {
        // Aggregates go through a different query path than get(), and a scope
        // applied only on retrieval would leave count() leaking the true total.
        $this->enterWorkspace($this->legal);
        $inLegal = $model::query()->count();

        $this->enterWorkspace($this->consulting);
        $inConsulting = $model::query()->count();

        $total = $model::query()->acrossWorkspaces()->count();

        $this->assertGreaterThan(0, $inLegal);
        $this->assertGreaterThan(0, $inConsulting);
        $this->assertSame($total, $inLegal + $inConsulting);
    }

    #[DataProvider('scopedModels')]
    public function test_the_escape_hatch_sees_every_workspace(string $model): void
    {
        $this->enterWorkspace($this->legal);

        $workspaceIds = $model::query()->acrossWorkspaces()->pluck('workspace_id')->unique();

        $this->assertCount(2, $workspaceIds);
    }

    #[DataProvider('scopedModels')]
    public function test_where_workspace_targets_one_workspace_regardless_of_context(string $model): void
    {
        $this->enterWorkspace($this->legal);

        $rows = $model::query()->whereWorkspace($this->consulting)->get();

        $this->assertGreaterThan(0, $rows->count());
        $this->assertTrue($rows->every(
            fn ($row): bool => (int) $row->workspace_id === $this->consulting->id
        ));
    }

    #[DataProvider('scopedModels')]
    public function test_where_workspace_fails_closed_on_a_null_workspace(string $model): void
    {
        // Unlike the global scope, the explicit form treats "no workspace" as
        // "no rows" — it is the tool to reach for when a missing workspace
        // should stop a query rather than widen it.
        $this->assertSame(0, $model::query()->whereWorkspace(null)->count());
    }

    #[DataProvider('scopedModels')]
    public function test_without_a_workspace_context_the_scope_does_not_narrow(string $model): void
    {
        // Documented, deliberate fail-open. See WorkspaceScope: an unset
        // context cannot cross a provider boundary, because provider_id and the
        // policies still enforce that; making it fail closed instead would
        // blind every queue worker and console command, which run with no
        // authenticated user by definition.
        app(CurrentWorkspace::class)->setId(null);

        $this->assertSame(
            $model::query()->acrossWorkspaces()->count(),
            $model::query()->count(),
        );
    }

    public function test_an_audit_log_inherits_its_workspace_from_a_workspace_scoped_auditable(): void
    {
        // Stand in the legal workspace, but write an audit entry ABOUT a
        // document that lives in the consulting workspace. The entry must take
        // the document's workspace, not the ambient one — otherwise a
        // "document archived" line would file itself under the wrong portal.
        $this->enterWorkspace($this->legal);

        $log = AuditLog::factory()
            ->forProvider($this->tenant['provider'])
            ->create([
                'action' => 'document.archived',
                'auditable_type' => Document::class,
                'auditable_id' => $this->consultingDocument->id,
            ]);

        $this->assertSame($this->consulting->id, (int) $log->workspace_id);

        $persisted = AuditLog::query()->acrossWorkspaces()->findOrFail($log->id);
        $this->assertSame($this->consulting->id, (int) $persisted->workspace_id);
    }

    public function test_an_audit_log_with_no_workspace_scoped_auditable_falls_back_to_current_context(): void
    {
        // A team / billing / account event has no workspace-scoped auditable
        // (or none at all). It is stamped with whichever workspace the actor
        // was in — the same rule Matter uses when it has no parent.
        $this->enterWorkspace($this->legal);

        $log = AuditLog::factory()
            ->forProvider($this->tenant['provider'])
            ->create([
                'action' => 'auth.signed_in',
                'auditable_type' => null,
                'auditable_id' => null,
            ]);

        $this->assertSame($this->legal->id, (int) $log->workspace_id);
    }

    public function test_withoutContext_restores_the_previous_workspace_afterwards(): void
    {
        $this->enterWorkspace($this->legal);

        $scoped = Matter::query()->count();
        $unscoped = Matter::query()->acrossWorkspaces()->count();

        $everything = app(CurrentWorkspace::class)->withoutContext(
            fn (): int => Matter::query()->count()
        );

        // Counted against the fixture rather than a literal, so adding a record
        // to ProviderTenantScenario does not turn this into a false failure.
        $this->assertGreaterThan($scoped, $unscoped);
        $this->assertSame($unscoped, $everything);

        $this->assertSame($this->legal->id, app(CurrentWorkspace::class)->id());
        $this->assertSame($scoped, Matter::query()->count());
    }

    public function test_withoutContext_restores_the_workspace_even_when_the_callback_throws(): void
    {
        $this->enterWorkspace($this->legal);

        try {
            app(CurrentWorkspace::class)->withoutContext(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // Expected — what matters is the state left behind.
        }

        $this->assertSame($this->legal->id, app(CurrentWorkspace::class)->id());
    }

    public function test_reading_a_workspaces_own_children_works_from_inside_another_workspace(): void
    {
        // Asking a workspace for its documents already names the workspace, so
        // the relation must not be re-filtered by the ambient context — that
        // would return nothing rather than the rows plainly asked for.
        $this->enterWorkspace($this->legal);

        $this->assertGreaterThan(0, $this->consulting->documents()->count());
        $this->assertGreaterThan(0, $this->consulting->matters()->count());
        $this->assertGreaterThan(0, $this->consulting->envelopes()->count());
    }
}
