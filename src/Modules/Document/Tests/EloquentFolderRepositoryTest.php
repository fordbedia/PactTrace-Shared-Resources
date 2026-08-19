<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\FolderRepository;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent\EloquentFolderRepository;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The Eloquent adapter behind the FolderRepository port. Small surface —
 * create plus one flat read — but `allForProvider` is what the whole FOLDERS
 * panel is built from, so both the tenant filter and the ordering matter.
 */
class EloquentFolderRepositoryTest extends BaseTest
{
    private EloquentFolderRepository $repository;

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentFolderRepository();
        $this->tenant = ProviderTenantScenario::make('folderrepo-a');
        $this->otherTenant = ProviderTenantScenario::make('folderrepo-b');
    }

    public function test_it_is_the_bound_implementation_of_the_port(): void
    {
        $this->assertInstanceOf(EloquentFolderRepository::class, app(FolderRepository::class));
    }

    public function test_create_persists_a_folder(): void
    {
        $folder = $this->repository->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
            'parent_id' => null,
            'name' => 'Discovery',
        ]);

        $this->assertInstanceOf(Folder::class, $folder);
        $this->assertDatabaseHas('folders', [
            'id' => $folder->id,
            'name' => 'Discovery',
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);
    }

    public function test_create_persists_a_nested_folder(): void
    {
        $parent = Folder::factory()->create(['provider_id' => $this->tenant['provider']->id]);

        $child = $this->repository->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => null,
            'matter_id' => null,
            'parent_id' => $parent->id,
            'name' => 'Exhibits',
        ]);

        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_all_for_provider_returns_only_that_providers_folders(): void
    {
        Folder::factory()->create(['provider_id' => $this->tenant['provider']->id, 'name' => 'Ours']);
        Folder::factory()->create(['provider_id' => $this->otherTenant['provider']->id, 'name' => 'Theirs']);

        $names = $this->repository->allForProvider($this->tenant['provider']->id)->pluck('name')->all();

        $this->assertSame(['Ours'], $names);
    }

    public function test_all_for_provider_returns_the_tree_flat_and_ordered_by_name(): void
    {
        // Flat, not nested — ListFolderTree is what turns this into a tree,
        // and it groups by parent_id in memory, so nesting here would be
        // duplicated work.
        $parent = Folder::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'name' => 'Beta',
        ]);
        Folder::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'name' => 'Alpha',
            'parent_id' => $parent->id,
        ]);

        $folders = $this->repository->allForProvider($this->tenant['provider']->id);

        $this->assertSame(['Alpha', 'Beta'], $folders->pluck('name')->all());
        $this->assertCount(2, $folders, 'Children come back in the same flat collection as their parent.');
    }

    public function test_all_for_provider_is_empty_for_a_provider_with_no_folders(): void
    {
        $this->assertTrue($this->repository->allForProvider($this->tenant['provider']->id)->isEmpty());
    }
}
