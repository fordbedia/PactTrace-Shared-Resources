<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\FolderData;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\CreateFolder;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The use case behind the "+" (new folder) action on /dashboard/documents.
 *
 * Thin by design, so the value of covering it is the *contract*: which
 * columns it writes, and that it writes exactly what the DTO carries rather
 * than deriving anything of its own — FolderController is what resolves a
 * nested folder's inherited client/matter scope, and a use case that quietly
 * second-guessed it would make that resolution untestable.
 */
class CreateFolderTest extends BaseTest
{
    private CreateFolder $createFolder;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createFolder = app(CreateFolder::class);
        $this->tenant = ProviderTenantScenario::make('createfolder');
    }

    public function test_it_persists_a_top_level_folder(): void
    {
        $folder = $this->createFolder->handle(new FolderData(
            id: null,
            parent_id: null,
            provider_id: $this->tenant['provider']->id,
            client_id: null,
            matter_id: null,
            name: 'Client Matters',
        ));

        $this->assertInstanceOf(Folder::class, $folder);
        $this->assertTrue($folder->exists);
        $this->assertNull($folder->parent_id);
        $this->assertDatabaseHas('folders', [
            'id' => $folder->id,
            'provider_id' => $this->tenant['provider']->id,
            'name' => 'Client Matters',
            'parent_id' => null,
        ]);
    }

    public function test_it_persists_the_scope_it_is_given(): void
    {
        $folder = $this->createFolder->handle(new FolderData(
            id: null,
            parent_id: null,
            provider_id: $this->tenant['provider']->id,
            client_id: $this->tenant['client']->id,
            matter_id: $this->tenant['matter']->id,
            name: 'Pleadings',
        ));

        $this->assertSame($this->tenant['client']->id, $folder->client_id);
        $this->assertSame($this->tenant['matter']->id, $folder->matter_id);
    }

    public function test_it_nests_under_a_parent(): void
    {
        $parent = Folder::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);

        $child = $this->createFolder->handle(new FolderData(
            id: null,
            parent_id: $parent->id,
            provider_id: $this->tenant['provider']->id,
            // Scope copied down from the parent by the controller — this use
            // case takes it as given rather than resolving it itself.
            client_id: $parent->client_id,
            matter_id: $parent->matter_id,
            name: 'Exhibits',
        ));

        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame($parent->client_id, $child->client_id);
        $this->assertSame($parent->matter_id, $child->matter_id);
    }

    public function test_it_nests_arbitrarily_deep(): void
    {
        // The frontend tree recurses rather than assuming a fixed depth, so
        // nothing here may cap it either.
        $parentId = null;

        foreach (['Level 1', 'Level 2', 'Level 3', 'Level 4'] as $name) {
            $folder = $this->createFolder->handle(new FolderData(
                id: null,
                parent_id: $parentId,
                provider_id: $this->tenant['provider']->id,
                client_id: null,
                matter_id: null,
                name: $name,
            ));

            $this->assertSame($parentId, $folder->parent_id);
            $parentId = $folder->id;
        }

        $this->assertDatabaseCount('folders', 4);
    }
}
