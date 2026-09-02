<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Tests;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The `fromUserDefault()` step added to RequestWorkspaceContext's precedence,
 * between the session and the "sole workspace" fallback.
 *
 * Every case gives the provider TWO live workspaces on purpose, so
 * soleWorkspaceOf() resolves to null and `fromUserDefault()` is the step under
 * test — otherwise the sole-workspace fallback would mask it.
 */
class RequestWorkspaceContextTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    private Workspace $primary;

    private Workspace $secondary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('ctx');
        $this->primary = $this->tenant['workspace'];
        $this->secondary = Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'ctx secondary']);
    }

    private function resolveFor(User $user): ?int
    {
        $this->actingAs($user->fresh());

        $context = app(CurrentWorkspace::class);
        $context->forget();

        return $context->id();
    }

    private function setDefault(?int $workspaceId): User
    {
        $owner = $this->tenant['owner'];
        $owner->forceFill(['default_workspace_id' => $workspaceId])->save();

        return $owner;
    }

    public function test_a_valid_same_tenant_default_is_used(): void
    {
        $user = $this->setDefault($this->secondary->id);

        $this->assertSame($this->secondary->id, $this->resolveFor($user));
    }

    public function test_a_null_default_falls_through(): void
    {
        $user = $this->setDefault(null);

        // Two live workspaces -> soleWorkspaceOf() is null too.
        $this->assertNull($this->resolveFor($user));
    }

    public function test_a_cross_tenant_default_is_rejected(): void
    {
        $other = ProviderTenantScenario::make('ctx-other');
        $user = $this->setDefault($other['workspace']->id);

        $this->assertNull($this->resolveFor($user));
    }

    public function test_a_soft_deleted_default_is_rejected(): void
    {
        $trashed = Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'ctx trashed']);
        $trashed->delete();

        // primary + secondary are still live (2), so the fallback is still null.
        $user = $this->setDefault($trashed->id);

        $this->assertNull($this->resolveFor($user));
    }
}
