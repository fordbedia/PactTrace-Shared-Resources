<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Client\Models\ClientInvitation;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for the Account Settings "Deactivate Workspace" surface:
 *
 *   GET    /api/v1/workspaces                                       list
 *   GET    /api/v1/workspaces/{workspace}/deactivation-eligibility  pre-flight
 *   DELETE /api/v1/workspaces/{workspace}                           confirmed submit
 *
 * The interesting logic is the blocker set (WorkspaceDeactivationPolicy) —
 * open matters, documents out for signature, non-terminal envelopes — plus
 * the acting-user name/password confirmation, and that deactivation is a soft
 * delete that quietly expires any still-open client invitation scoped to the
 * workspace (an unaccepted invitation is not a blocker).
 */
class WorkspaceControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('ws');
    }

    private function owner(): User
    {
        return $this->tenant['owner'];
    }

    /** A fresh workspace with no matters/documents/envelopes of its own. */
    private function emptyWorkspace(): Workspace
    {
        return Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'Empty ' . uniqid()]);
    }

    /** A primary workspace — the one RegisterProvider stamps at sign-up. */
    private function primaryWorkspace(): Workspace
    {
        return Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->primary()
            ->create(['name' => 'Primary ' . uniqid()]);
    }

    private function matterIn(Workspace $workspace, string $status, ?int $clientId = null): Matter
    {
        return Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $workspace->id,
            'client_id' => $clientId ?? $this->tenant['client']->id,
            'status' => $status,
        ]);
    }

    // ── auth ─────────────────────────────────────────────────────────────

    public function test_every_endpoint_requires_authentication(): void
    {
        $workspace = $this->tenant['workspace'];

        $this->getJson('/api/v1/workspaces')->assertStatus(401);
        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")->assertStatus(401);
        $this->deleteJson("/api/v1/workspaces/{$workspace->id}", [])->assertStatus(401);
    }

    public function test_a_client_role_user_cannot_list_workspaces(): void
    {
        Sanctum::actingAs($this->tenant['clientUser']);

        $this->getJson('/api/v1/workspaces')->assertStatus(403);
    }

    // ── index ───────────────────────────────────────────────────────────

    public function test_it_lists_the_active_workspaces_for_the_provider(): void
    {
        $extra = $this->emptyWorkspace();
        $deactivated = $this->emptyWorkspace();
        $deactivated->delete();

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->tenant['workspace']->id])
            ->assertJsonFragment(['id' => $extra->id])
            ->assertJsonMissing(['id' => $deactivated->id]);
    }

    // ── deactivation-eligibility ────────────────────────────────────────

    public function test_an_empty_workspace_is_eligible_for_deactivation(): void
    {
        $workspace = $this->emptyWorkspace();

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('blockers', []);
    }

    public function test_a_completed_matter_alone_does_not_block(): void
    {
        $workspace = $this->emptyWorkspace();
        $this->matterIn($workspace, 'completed');

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")
            ->assertOk()
            ->assertJsonPath('eligible', true);
    }

    public function test_an_open_matter_blocks_deactivation(): void
    {
        $workspace = $this->emptyWorkspace();
        $this->matterIn($workspace, 'active');

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonFragment(['code' => 'open_matters']);
    }

    public function test_a_document_out_for_signature_blocks_deactivation(): void
    {
        $workspace = $this->emptyWorkspace();
        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $workspace->id,
            'client_id' => $this->tenant['client']->id,
            'uploaded_by' => $this->owner()->id,
            'status' => 'sent',
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonFragment(['code' => 'pending_documents']);
    }

    public function test_a_non_terminal_envelope_blocks_deactivation(): void
    {
        $workspace = $this->emptyWorkspace();
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $workspace->id,
            'client_id' => $this->tenant['client']->id,
            'uploaded_by' => $this->owner()->id,
            'status' => 'draft',
        ]);
        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $workspace->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => 'sent',
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonFragment(['code' => 'pending_envelopes']);
    }

    public function test_the_primary_workspace_is_never_eligible_for_deactivation(): void
    {
        // No matters, no documents, no envelopes — the only reason is that it
        // is the primary workspace, and that alone is enough.
        $workspace = $this->primaryWorkspace();

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonFragment(['code' => 'is_primary_workspace']);
    }

    public function test_the_primary_workspace_reports_only_the_primary_blocker_even_with_activity(): void
    {
        $workspace = $this->primaryWorkspace();
        $this->matterIn($workspace, 'active');

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")
            ->assertOk()
            ->assertJsonPath('eligible', false)
            // The primary short-circuit means the signal reader never runs, so
            // open_matters is not even reported.
            ->assertJsonPath('blockers', [[
                'code' => 'is_primary_workspace',
                'label' => 'This is your primary workspace',
                'detail' => "Primary workspaces can't be deactivated. If you want to reorganize your practice, create additional workspaces instead.",
            ]]);
    }

    public function test_the_index_exposes_is_primary(): void
    {
        Sanctum::actingAs($this->owner());

        // ProviderTenantScenario's workspace is not primary; add one that is.
        $primary = $this->primaryWorkspace();

        $this->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonFragment(['id' => $primary->id, 'is_primary' => true])
            ->assertJsonFragment(['id' => $this->tenant['workspace']->id, 'is_primary' => false]);
    }

    public function test_an_unaccepted_client_invitation_tied_to_the_workspace_does_not_block_deactivation(): void
    {
        $workspace = $this->emptyWorkspace();
        // A completed matter links the client to the workspace without also
        // tripping the open-matters blocker.
        $this->matterIn($workspace, 'completed');
        ClientInvitation::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'invited_by' => $this->owner()->id,
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('blockers', []);
    }

    public function test_an_unaccepted_staff_invitation_does_not_block_deactivation(): void
    {
        $workspace = $this->emptyWorkspace();
        // Team invitations carry no workspace link, so deactivating one
        // workspace neither blocks on nor withdraws them — this just guards
        // against a regression that would re-add them as a blocker.
        TeamInvitation::factory()->forProvider($this->tenant['provider'])->create();
        $this->owner()->forceFill(['name' => 'Olivia Owner', 'password' => 'right-password'])->save();

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/deactivation-eligibility")
            ->assertOk()
            ->assertJsonPath('eligible', true);

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Olivia Owner',
            'password' => 'right-password',
        ])->assertStatus(204);

        $this->assertSoftDeleted('workspaces', ['id' => $workspace->id]);
    }

    public function test_deactivation_expires_a_still_open_client_invitation_scoped_to_the_workspace(): void
    {
        $workspace = $this->emptyWorkspace();
        $this->matterIn($workspace, 'completed');
        $invitation = ClientInvitation::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'invited_by' => $this->owner()->id,
            'expires_at' => now()->addDays(7),
        ]);

        // A second workspace's client invitation must be left untouched.
        $otherWorkspace = $this->emptyWorkspace();
        $this->matterIn($otherWorkspace, 'completed', $this->tenant['otherClient']->id);
        $untouched = ClientInvitation::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['otherClient']->id,
            'invited_by' => $this->owner()->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->owner()->forceFill(['name' => 'Olivia Owner', 'password' => 'right-password'])->save();

        Sanctum::actingAs($this->owner());

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Olivia Owner',
            'password' => 'right-password',
        ])->assertStatus(204);

        $this->assertTrue(
            $invitation->fresh()->expires_at->lessThanOrEqualTo(now()),
            'the workspace-scoped invitation should be expired'
        );
        $this->assertTrue(
            $untouched->fresh()->expires_at->greaterThan(now()),
            'another workspace\'s invitation should be left alone'
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'workspace.deactivated',
            'auditable_id' => $workspace->id,
        ]);
    }

    // ── destroy ─────────────────────────────────────────────────────────

    public function test_it_soft_deletes_the_workspace_on_a_valid_confirmation(): void
    {
        $workspace = $this->emptyWorkspace();
        $this->owner()->forceFill(['name' => 'Olivia Owner', 'password' => 'right-password'])->save();

        Sanctum::actingAs($this->owner());

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'olivia owner', // case-insensitive
            'password' => 'right-password',
        ])->assertStatus(204);

        $this->assertSoftDeleted('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->owner()->id,
            'action' => 'workspace.deactivated',
            'auditable_id' => $workspace->id,
        ]);
    }

    public function test_it_rejects_a_name_that_does_not_match(): void
    {
        $workspace = $this->emptyWorkspace();
        $this->owner()->forceFill(['name' => 'Olivia Owner', 'password' => 'right-password'])->save();

        Sanctum::actingAs($this->owner());

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Someone Else',
            'password' => 'right-password',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'deleted_at' => null]);
    }

    public function test_it_rejects_a_wrong_password(): void
    {
        $workspace = $this->emptyWorkspace();
        $this->owner()->forceFill(['name' => 'Olivia Owner', 'password' => 'right-password'])->save();

        Sanctum::actingAs($this->owner());

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Olivia Owner',
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_it_refuses_deactivation_while_a_blocker_is_present(): void
    {
        $workspace = $this->emptyWorkspace();
        $this->matterIn($workspace, 'active');
        $this->owner()->forceFill(['name' => 'Olivia Owner', 'password' => 'right-password'])->save();

        Sanctum::actingAs($this->owner());

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Olivia Owner',
            'password' => 'right-password',
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'blocked')
            ->assertJsonFragment(['code' => 'open_matters']);

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'deleted_at' => null]);
    }

    public function test_it_refuses_to_deactivate_the_primary_workspace_regardless_of_activity(): void
    {
        $workspace = $this->primaryWorkspace();
        // An open matter would normally be its own blocker — irrelevant here,
        // the primary check short-circuits before signals are read.
        $this->matterIn($workspace, 'active');
        $this->owner()->forceFill(['name' => 'Olivia Owner', 'password' => 'right-password'])->save();

        Sanctum::actingAs($this->owner());

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Olivia Owner',
            'password' => 'right-password',
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'blocked')
            ->assertJsonPath('blockers', [[
                'code' => 'is_primary_workspace',
                'label' => 'This is your primary workspace',
                'detail' => "Primary workspaces can't be deactivated. If you want to reorganize your practice, create additional workspaces instead.",
            ]]);

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'deleted_at' => null]);
    }

    public function test_a_staff_user_cannot_deactivate_a_workspace(): void
    {
        $workspace = $this->emptyWorkspace();

        Sanctum::actingAs($this->tenant['staff']);

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'x',
            'password' => 'y',
        ])->assertStatus(403);
    }

    public function test_a_workspace_from_another_provider_is_a_404(): void
    {
        $other = ProviderTenantScenario::make('ws-other');

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/workspaces/{$other['workspace']->id}/deactivation-eligibility")
            ->assertStatus(404);

        $this->deleteJson("/api/v1/workspaces/{$other['workspace']->id}", [
            'name' => 'x',
            'password' => 'y',
        ])->assertStatus(404);
    }

    // ── store ───────────────────────────────────────────────────────────

    public function test_it_creates_an_additional_workspace_for_the_provider(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/v1/workspaces', [
            'name' => 'Second Practice',
            'workspace_type' => 'legal',
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Second Practice')
            ->assertJsonPath('data.workspace_type', 'legal')
            // Blank labels filled from the type preset by the model hook.
            ->assertJsonPath('data.client_label', 'Your Attorney');

        $this->assertDatabaseHas('workspaces', [
            'provider_id' => $this->tenant['provider']->id,
            'owner_id' => $this->owner()->id,
            'name' => 'Second Practice',
            'workspace_type' => 'legal',
        ]);
    }

    public function test_store_rejects_an_invalid_workspace_type(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/v1/workspaces', [
            'name' => 'Bad Type',
            'workspace_type' => 'accountancy',
        ])->assertStatus(422)->assertJsonValidationErrors('workspace_type');
    }

    public function test_store_rejects_a_missing_name(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/v1/workspaces', ['workspace_type' => 'general'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_admin_may_create_a_workspace_but_a_staff_user_may_not(): void
    {
        $admin = User::factory()->create(['provider_id' => $this->tenant['provider']->id]);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/workspaces', ['name' => 'Admin Made', 'workspace_type' => 'general'])
            ->assertStatus(201);

        Sanctum::actingAs($this->tenant['staff']);
        $this->postJson('/api/v1/workspaces', ['name' => 'Staff Made', 'workspace_type' => 'general'])
            ->assertStatus(403);
    }

    // ── update ──────────────────────────────────────────────────────────

    public function test_it_updates_name_type_and_labels(): void
    {
        $workspace = $this->emptyWorkspace();

        Sanctum::actingAs($this->owner());

        $this->putJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Renamed',
            'workspace_type' => 'accounting',
            'client_label' => 'Taxpayer',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.workspace_type', 'accounting')
            ->assertJsonPath('data.client_label', 'Taxpayer')
            // Blank engagement label refilled from the accounting preset.
            ->assertJsonPath('data.engagement_label', 'Engagement');

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'name' => 'Renamed',
            'workspace_type' => 'accounting',
        ]);
    }

    public function test_update_by_a_staff_user_is_allowed(): void
    {
        $workspace = $this->emptyWorkspace();

        Sanctum::actingAs($this->tenant['staff']);

        $this->putJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Staff Renamed',
            'workspace_type' => 'general',
        ])->assertOk();
    }

    public function test_update_of_a_cross_tenant_workspace_is_a_404(): void
    {
        $other = ProviderTenantScenario::make('ws-other-update');

        Sanctum::actingAs($this->owner());

        $this->putJson("/api/v1/workspaces/{$other['workspace']->id}", [
            'name' => 'Hijacked',
            'workspace_type' => 'general',
        ])->assertStatus(404);
    }

    // ── activate ────────────────────────────────────────────────────────

    public function test_activate_sets_the_session_and_the_users_default_workspace(): void
    {
        $workspace = $this->emptyWorkspace();

        Sanctum::actingAs($this->owner());

        $this->postJson("/api/v1/workspaces/{$workspace->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.id', $workspace->id);

        $this->assertSame($workspace->id, session('workspace_id'));
        $this->assertSame($workspace->id, (int) $this->owner()->fresh()->default_workspace_id);
    }

    public function test_a_staff_user_may_activate_a_workspace(): void
    {
        $workspace = $this->emptyWorkspace();

        Sanctum::actingAs($this->tenant['staff']);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/activate")->assertOk();
        $this->assertSame($workspace->id, (int) $this->tenant['staff']->fresh()->default_workspace_id);
    }

    public function test_a_client_user_cannot_activate_a_workspace(): void
    {
        $workspace = $this->emptyWorkspace();

        Sanctum::actingAs($this->tenant['clientUser']);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/activate")->assertStatus(403);
    }

    public function test_activate_of_a_cross_tenant_workspace_is_a_404(): void
    {
        $other = ProviderTenantScenario::make('ws-other-activate');

        Sanctum::actingAs($this->owner());

        $this->postJson("/api/v1/workspaces/{$other['workspace']->id}/activate")->assertStatus(404);
    }

    // ── index: include_deactivated ──────────────────────────────────────

    public function test_index_excludes_deactivated_workspaces_by_default(): void
    {
        $deactivated = $this->emptyWorkspace();
        $deactivated->delete();

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonMissing(['id' => $deactivated->id]);
    }

    public function test_index_includes_deactivated_workspaces_when_asked(): void
    {
        $deactivated = $this->emptyWorkspace();
        $deactivated->delete();

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/workspaces?include_deactivated=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $deactivated->id])
            ->assertJsonFragment(['id' => $this->tenant['workspace']->id]);
    }

    public function test_index_never_leaks_another_providers_deactivated_workspace(): void
    {
        $other = ProviderTenantScenario::make('ws-other-incl');
        $otherDeactivated = Workspace::factory()->forProvider($other['provider'])->create();
        $otherDeactivated->delete();

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/workspaces?include_deactivated=1')
            ->assertOk()
            ->assertJsonMissing(['id' => $otherDeactivated->id]);
    }

    // ── update: type is immutable once specialised ─────────────────────

    public function test_update_ignores_a_type_change_on_a_specialised_workspace(): void
    {
        $workspace = Workspace::factory()->forProvider($this->tenant['provider'])
            ->ofType(WorkspaceType::Legal)
            ->create(['name' => 'Frozen ' . uniqid()]);

        Sanctum::actingAs($this->owner());

        $this->putJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => $workspace->name,
            'workspace_type' => 'accounting',
        ])->assertOk()
            ->assertJsonPath('data.workspace_type', 'legal');

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'workspace_type' => 'legal',
        ]);
    }

    // ── restore ─────────────────────────────────────────────────────────

    private function deactivatedWorkspace(): Workspace
    {
        $workspace = $this->emptyWorkspace();
        $workspace->delete();

        return $workspace;
    }

    public function test_restore_requires_authentication(): void
    {
        $workspace = $this->deactivatedWorkspace();

        $this->postJson("/api/v1/workspaces/{$workspace->id}/restore")->assertStatus(401);
    }

    public function test_restore_reactivates_the_workspace_and_audits_it(): void
    {
        $workspace = $this->deactivatedWorkspace();

        Sanctum::actingAs($this->owner());

        $this->postJson("/api/v1/workspaces/{$workspace->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $workspace->id)
            ->assertJsonPath('data.deleted_at', null);

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->owner()->id,
            'action' => 'workspace.reactivated',
            'auditable_id' => $workspace->id,
        ]);
    }

    public function test_restore_of_an_active_workspace_is_a_harmless_noop(): void
    {
        $workspace = $this->emptyWorkspace();

        Sanctum::actingAs($this->owner());

        $this->postJson("/api/v1/workspaces/{$workspace->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $workspace->id);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'workspace.reactivated',
            'auditable_id' => $workspace->id,
        ]);
    }

    public function test_a_staff_user_cannot_restore_a_workspace(): void
    {
        $workspace = $this->deactivatedWorkspace();

        Sanctum::actingAs($this->tenant['staff']);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/restore")->assertStatus(403);
        $this->assertSoftDeleted('workspaces', ['id' => $workspace->id]);
    }

    public function test_restore_of_a_cross_tenant_workspace_is_a_404(): void
    {
        $other = ProviderTenantScenario::make('ws-other-restore');
        $otherWorkspace = Workspace::factory()->forProvider($other['provider'])->create();
        $otherWorkspace->delete();

        Sanctum::actingAs($this->owner());

        $this->postJson("/api/v1/workspaces/{$otherWorkspace->id}/restore")->assertStatus(404);
        $this->assertSoftDeleted('workspaces', ['id' => $otherWorkspace->id]);
    }

    public function test_restore_of_an_unknown_workspace_is_a_404(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/v1/workspaces/999999/restore')->assertStatus(404);
    }
}
