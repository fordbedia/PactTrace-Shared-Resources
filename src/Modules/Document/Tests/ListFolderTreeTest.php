<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Support\Facades\DB;
use PactTraceSDK\SharedResources\Modules\Document\Application\UseCases\ListFolderTree;
use PactTraceSDK\SharedResources\Modules\Document\Models\Folder;
use PactTraceSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTraceSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTraceSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Turns the flat `folders` rows into the nested shape FolderTreeItem renders
 * on /dashboard/documents (see .claude/rules/document.md).
 *
 * The two things worth pinning down: the nesting is correct at arbitrary
 * depth (the frontend recurses, so a use case that only handled two levels
 * would silently drop grandchildren), and it stays *one* query however deep
 * the tree gets — the whole reason the grouping happens in memory.
 */
class ListFolderTreeTest extends BaseTest
{
    private ListFolderTree $listFolderTree;

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listFolderTree = app(ListFolderTree::class);
        $this->tenant = ProviderTenantScenario::make('foldertree-a');
        $this->otherTenant = ProviderTenantScenario::make('foldertree-b');
    }

    public function test_it_returns_an_empty_tree_when_there_are_no_folders(): void
    {
        $this->assertSame([], $this->listFolderTree->handle($this->tenant['provider']->id));
    }

    public function test_it_nests_children_under_their_parent(): void
    {
        $parent = $this->folder('Client Matters');
        $this->folder('NDA', $parent->id);
        $this->folder('Retainers', $parent->id);

        $tree = $this->listFolderTree->handle($this->tenant['provider']->id);

        $this->assertCount(1, $tree, 'Only roots appear at the top level.');
        $this->assertSame('Client Matters', $tree[0]['name']);
        $this->assertSame(['NDA', 'Retainers'], array_column($tree[0]['children'], 'name'));
    }

    public function test_it_nests_arbitrarily_deep(): void
    {
        $one = $this->folder('Level 1');
        $two = $this->folder('Level 2', $one->id);
        $three = $this->folder('Level 3', $two->id);
        $this->folder('Level 4', $three->id);

        $tree = $this->listFolderTree->handle($this->tenant['provider']->id);

        $this->assertSame('Level 1', $tree[0]['name']);
        $this->assertSame('Level 2', $tree[0]['children'][0]['name']);
        $this->assertSame('Level 3', $tree[0]['children'][0]['children'][0]['name']);
        $this->assertSame('Level 4', $tree[0]['children'][0]['children'][0]['children'][0]['name']);
        $this->assertSame([], $tree[0]['children'][0]['children'][0]['children'][0]['children']);
    }

    public function test_every_node_carries_the_shape_the_frontend_expects(): void
    {
        // normalizeFolderTree on the frontend reads exactly these keys.
        $folder = $this->folder('Pleadings', null, [
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);

        $node = $this->listFolderTree->handle($this->tenant['provider']->id)[0];

        $this->assertSame(
            ['id', 'name', 'parent_id', 'client_id', 'matter_id', 'children'],
            array_keys($node),
        );
        $this->assertSame($folder->id, $node['id']);
        $this->assertNull($node['parent_id']);
        $this->assertSame($this->tenant['client']->id, $node['client_id']);
        $this->assertSame($this->tenant['matter']->id, $node['matter_id']);
    }

    public function test_it_returns_only_the_given_providers_folders(): void
    {
        $this->folder('Ours');
        Folder::factory()->create([
            'provider_id' => $this->otherTenant['provider']->id,
            'name' => 'Theirs',
        ]);

        $tree = $this->listFolderTree->handle($this->tenant['provider']->id);

        $this->assertSame(['Ours'], array_column($tree, 'name'));
    }

    public function test_a_deep_tree_still_costs_one_query(): void
    {
        $parentId = null;
        for ($i = 0; $i < 10; $i++) {
            $parentId = $this->folder("Level {$i}", $parentId)->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->listFolderTree->handle($this->tenant['provider']->id);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(
            1,
            $queries,
            'The tree is grouped in memory — a per-node query would N+1 with depth.'
        );
    }

    private function folder(string $name, ?int $parentId = null, array $attributes = []): Folder
    {
        return Folder::factory()->create(array_merge([
            'provider_id' => $this->tenant['provider']->id,
            'name' => $name,
            'parent_id' => $parentId,
        ], $attributes));
    }
}
