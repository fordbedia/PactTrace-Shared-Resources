<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The HTTP surface behind the FOLDERS panel on /dashboard/documents —
 * GET/POST /api/folders, DELETE /api/folders/{folder}.
 *
 * Two behaviours here carry real weight beyond "it saves a row":
 *
 *  - a nested create *inherits* its parent's client/matter scope instead of
 *    trusting the request, because CreateFolderModal only ever sends a name;
 *  - deleting a folder cascades to its subtree but only *unfiles* the
 *    documents inside it — the difference between tidying up and destroying a
 *    client's paperwork.
 */
class FolderControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $otherTenant;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('folder-http-a');
        $this->otherTenant = ProviderTenantScenario::make('folder-http-b');
    }

    /* ── index ─────────────────────────────────────────────────────────── */

    public function test_listing_folders_requires_being_signed_in(): void
    {
        $this->getJson('/api/folders')
            ->assertStatus(401)
            ->assertJsonPath('message', 'You must be signed in to a provider account to view folders.');
    }

    public function test_a_user_with_no_provider_is_refused(): void
    {
        $orphan = User::factory()->create(['provider_id' => null]);

        $this->actingAs($orphan)->getJson('/api/folders')->assertStatus(401);
    }

    public function test_it_returns_the_tree_already_nested(): void
    {
        $parent = $this->folder('Client Matters');
        $this->folder('NDA', $parent->id);

        $response = $this->actingAs($this->tenant['owner'])->getJson('/api/folders');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Client Matters')
            ->assertJsonPath('data.0.children.0.name', 'NDA')
            ->assertJsonCount(1, 'data');
    }

    public function test_it_returns_only_the_tenants_folders(): void
    {
        $this->folder('Ours');
        Folder::factory()->create(['provider_id' => $this->otherTenant['provider']->id, 'name' => 'Theirs']);

        $names = $this->actingAs($this->tenant['owner'])->getJson('/api/folders')->json('data.*.name');

        $this->assertSame(['Ours'], $names);
    }

    public function test_a_client_user_may_read_the_tree(): void
    {
        // The client role holds folder.view but not folder.create/delete.
        $this->folder('Client Matters');

        $this->actingAs($this->tenant['clientUser'])->getJson('/api/folders')->assertOk();
    }

    /* ── store ─────────────────────────────────────────────────────────── */

    public function test_creating_a_folder_requires_being_signed_in(): void
    {
        $this->postJson('/api/folders', ['name' => 'New'])->assertStatus(401);
    }

    public function test_it_creates_a_top_level_folder(): void
    {
        $response = $this->actingAs($this->tenant['owner'])
            ->postJson('/api/folders', ['name' => 'Client Matters']);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Client Matters')
            ->assertJsonPath('data.parent_id', null);

        $this->assertDatabaseHas('folders', [
            'id' => $response->json('data.id'),
            'provider_id' => $this->tenant['provider']->id,
            'name' => 'Client Matters',
        ]);
    }

    public function test_a_nested_folder_inherits_its_parents_scope(): void
    {
        // CreateFolderModal sends nothing but a name and a parent_id, so this
        // inheritance is the only thing that keeps a subfolder consistent with
        // the folder it lives in.
        $parent = $this->folder('Client Matters', null, [
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson('/api/folders', ['name' => 'NDA', 'parent_id' => $parent->id]);

        $response->assertSuccessful()
            ->assertJsonPath('data.parent_id', $parent->id)
            ->assertJsonPath('data.client_id', $this->tenant['client']->id)
            ->assertJsonPath('data.matter_id', $this->tenant['matter']->id);
    }

    public function test_a_nested_folder_ignores_a_conflicting_scope_in_the_request(): void
    {
        // The parent wins: a subfolder that disagreed with its parent about
        // which client it belongs to would be an inconsistent tree.
        $parent = $this->folder('Client Matters', null, ['client_id' => $this->tenant['client']->id]);

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/folders', [
                'name' => 'NDA',
                'parent_id' => $parent->id,
                'client_id' => $this->tenant['otherClient']->id,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.client_id', $this->tenant['client']->id);
    }

    public function test_it_nests_arbitrarily_deep(): void
    {
        $parentId = null;

        foreach (['Level 1', 'Level 2', 'Level 3'] as $name) {
            $response = $this->actingAs($this->tenant['owner'])
                ->postJson('/api/folders', ['name' => $name, 'parent_id' => $parentId]);

            $response->assertSuccessful()->assertJsonPath('data.parent_id', $parentId);
            $parentId = $response->json('data.id');
        }

        $tree = $this->actingAs($this->tenant['owner'])->getJson('/api/folders')->json('data');

        $this->assertSame('Level 3', $tree[0]['children'][0]['children'][0]['name']);
    }

    public function test_it_creates_a_folder_scoped_to_a_matter(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/folders', ['name' => 'Pleadings', 'matter_id' => $this->tenant['matter']->id])
            ->assertSuccessful()
            ->assertJsonPath('data.matter_id', $this->tenant['matter']->id);
    }

    public function test_it_requires_a_name(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/folders', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_it_rejects_a_parent_that_does_not_exist(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/folders', ['name' => 'NDA', 'parent_id' => 999_999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_it_refuses_to_nest_under_another_tenants_folder(): void
    {
        $foreign = Folder::factory()->create(['provider_id' => $this->otherTenant['provider']->id]);

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/folders', ['name' => 'NDA', 'parent_id' => $foreign->id])
            ->assertStatus(403);

        $this->assertDatabaseMissing('folders', ['name' => 'NDA']);
    }

    public function test_a_client_user_cannot_create_a_folder(): void
    {
        // Clients participate in the engagement; they do not reorganise the
        // provider's filing structure.
        $this->actingAs($this->tenant['clientUser'])
            ->postJson('/api/folders', ['name' => 'Mine'])
            ->assertStatus(403);
    }

    /* ── destroy ───────────────────────────────────────────────────────── */

    public function test_deleting_a_folder_requires_being_signed_in(): void
    {
        $folder = $this->folder('Client Matters');

        $this->deleteJson("/api/folders/{$folder->id}")->assertStatus(401);
        $this->assertDatabaseHas('folders', ['id' => $folder->id]);
    }

    public function test_it_deletes_a_folder(): void
    {
        $folder = $this->folder('Client Matters');

        $this->actingAs($this->tenant['owner'])
            ->deleteJson("/api/folders/{$folder->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
    }

    public function test_deleting_a_folder_removes_its_whole_subtree(): void
    {
        $parent = $this->folder('Client Matters');
        $child = $this->folder('NDA', $parent->id);
        $grandchild = $this->folder('2026', $child->id);

        $this->actingAs($this->tenant['owner'])
            ->deleteJson("/api/folders/{$parent->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('folders', ['id' => $child->id]);
        $this->assertDatabaseMissing('folders', ['id' => $grandchild->id]);
    }

    public function test_deleting_a_folder_unfiles_its_documents_rather_than_deleting_them(): void
    {
        // documents.folder_id is nullOnDelete: tidying up the filing structure
        // must never destroy a client's paperwork.
        $folder = $this->folder('Client Matters');
        $child = $this->folder('NDA', $folder->id);

        $inParent = $this->documentIn($folder);
        $inChild = $this->documentIn($child);

        $this->actingAs($this->tenant['owner'])
            ->deleteJson("/api/folders/{$folder->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('documents', ['id' => $inParent->id, 'folder_id' => null]);
        $this->assertDatabaseHas('documents', ['id' => $inChild->id, 'folder_id' => null]);
    }

    public function test_it_refuses_to_delete_another_tenants_folder(): void
    {
        $foreign = Folder::factory()->create(['provider_id' => $this->otherTenant['provider']->id]);

        $this->actingAs($this->tenant['owner'])
            ->deleteJson("/api/folders/{$foreign->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('folders', ['id' => $foreign->id]);
    }

    public function test_staff_cannot_delete_a_folder(): void
    {
        // folder.delete is an owner-only permission (see Role::permissions).
        $folder = $this->folder('Client Matters');

        $this->actingAs($this->tenant['staff'])
            ->deleteJson("/api/folders/{$folder->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('folders', ['id' => $folder->id]);
    }

    public function test_deleting_a_folder_that_does_not_exist_is_a_404(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->deleteJson('/api/folders/999999')
            ->assertStatus(404);
    }

    private function folder(string $name, ?int $parentId = null, array $attributes = []): Folder
    {
        return Folder::factory()->create(array_merge([
            'provider_id' => $this->tenant['provider']->id,
            'name' => $name,
            'parent_id' => $parentId,
        ], $attributes));
    }

    private function documentIn(Folder $folder): Document
    {
        return Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'folder_id' => $folder->id,
        ]);
    }
}
