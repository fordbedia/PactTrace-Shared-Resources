<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cross-tenant isolation, exercised through the real Gate rather than by
 * calling policy methods directly — a policy that is never registered would
 * otherwise pass its own unit tests while authorising everything in production.
 *
 * This is the highest-value test file in the module: a leak here means one
 * attorney reading another's client files.
 */
class TenantIsolationTest extends BaseTest
{
    private TestScenarioCollection $tenantA;

    private TestScenarioCollection $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = ProviderTenantScenario::make('a');
        $this->tenantB = ProviderTenantScenario::make('b');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function providerSideActors(): array
    {
        return [
            'owner' => ['owner'],
            'staff' => ['staff'],
        ];
    }

    #[DataProvider('providerSideActors')]
    public function test_provider_side_users_cannot_read_another_tenants_records(string $actor): void
    {
        $user = $this->tenantA[$actor];

        $this->assertFalse($user->can('view', $this->tenantB['client']));
        $this->assertFalse($user->can('view', $this->tenantB['matter']));
        $this->assertFalse($user->can('view', $this->tenantB['milestone']));
        $this->assertFalse($user->can('view', $this->tenantB['document']));
        $this->assertFalse($user->can('view', $this->tenantB['provider']));
    }

    #[DataProvider('providerSideActors')]
    public function test_provider_side_users_cannot_modify_another_tenants_records(string $actor): void
    {
        $user = $this->tenantA[$actor];

        $this->assertFalse($user->can('update', $this->tenantB['client']));
        $this->assertFalse($user->can('update', $this->tenantB['matter']));
        $this->assertFalse($user->can('update', $this->tenantB['milestone']));
        $this->assertFalse($user->can('update', $this->tenantB['document']));
        $this->assertFalse($user->can('delete', $this->tenantB['document']));
    }

    #[DataProvider('providerSideActors')]
    public function test_provider_side_users_can_work_inside_their_own_tenant(string $actor): void
    {
        $user = $this->tenantA[$actor];

        $this->assertTrue($user->can('view', $this->tenantA['client']));
        $this->assertTrue($user->can('view', $this->tenantA['matter']));
        $this->assertTrue($user->can('view', $this->tenantA['milestone']));
        $this->assertTrue($user->can('view', $this->tenantA['document']));
        $this->assertTrue($user->can('update', $this->tenantA['matter']));
    }

    public function test_creating_under_another_tenants_client_is_denied(): void
    {
        $owner = $this->tenantA['owner'];

        $this->assertTrue($owner->can('create', [Matter::class, $this->tenantA['client']]));
        $this->assertFalse($owner->can('create', [Matter::class, $this->tenantB['client']]));

        $this->assertTrue($owner->can('create', [Document::class, $this->tenantA['matter']]));
        $this->assertFalse($owner->can('create', [Document::class, $this->tenantB['matter']]));
    }

    public function test_only_the_owner_administers_the_tenant(): void
    {
        $provider = $this->tenantA['provider'];

        $this->assertTrue($this->tenantA['owner']->can('update', $provider));
        $this->assertTrue($this->tenantA['owner']->can('manageBilling', $provider));
        $this->assertTrue($this->tenantA['owner']->can('manageBranding', $provider));

        $this->assertFalse($this->tenantA['staff']->can('update', $provider));
        $this->assertFalse($this->tenantA['staff']->can('manageBilling', $provider));

        // ...and not somebody else's tenant, however senior they are.
        $this->assertFalse($this->tenantA['owner']->can('update', $this->tenantB['provider']));
        $this->assertFalse($this->tenantA['owner']->can('manageBilling', $this->tenantB['provider']));
    }

    public function test_a_user_with_no_provider_is_denied_everything(): void
    {
        // A staff account created before being attached to a tenant still holds
        // the role, and must not be able to act on any record anywhere.
        $orphan = User::factory()->create(['provider_id' => null]);
        $orphan->assignRole(Role::Staff->value);
        $orphan = $orphan->fresh();

        $this->assertFalse($orphan->can('view', $this->tenantA['client']));
        $this->assertFalse($orphan->can('view', $this->tenantA['matter']));
        $this->assertFalse($orphan->can('view', $this->tenantA['document']));
    }

    public function test_a_record_with_no_provider_is_denied_to_everyone(): void
    {
        // A system-initiated audit entry belongs to no tenant. Nobody's portal
        // should surface it, including the owner of every other tenant.
        $systemLog = AuditLog::factory()->create([
            'provider_id' => null,
            'user_id' => null,
        ]);

        $this->assertFalse($this->tenantA['owner']->can('view', $systemLog));
        $this->assertFalse($this->tenantB['owner']->can('view', $systemLog));

        // A tenant's own audit entries stay readable.
        $ownLog = AuditLog::factory()->create([
            'provider_id' => $this->tenantA['provider']->id,
            'user_id' => $this->tenantA['owner']->id,
        ]);

        $this->assertTrue($this->tenantA['owner']->can('view', $ownLog));
        $this->assertFalse($this->tenantB['owner']->can('view', $ownLog));
    }
}
