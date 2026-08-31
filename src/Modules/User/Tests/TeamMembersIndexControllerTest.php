<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for GET /api/v1/team/members — the paginated /dashboard/team
 * list (auth:sanctum, user.view). Registers SanctumServiceProvider and
 * authenticates with Sanctum::actingAs() for the same reason
 * TeamInvitationControllerTest does.
 */
class TeamMembersIndexControllerTest extends BaseTest
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

        Mail::fake();

        $this->tenant = ProviderTenantScenario::make('team-index');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/team/members')->assertStatus(401);
    }

    public function test_a_client_role_user_is_denied(): void
    {
        Sanctum::actingAs($this->tenant['clientUser']);

        $this->getJson('/api/v1/team/members')->assertStatus(403);
    }

    public function test_it_lists_only_this_tenants_provider_side_members(): void
    {
        // A second, unrelated tenant whose members must never appear.
        $other = ProviderTenantScenario::make('team-index-other');

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/team/members')->assertOk();

        $emails = collect($response->json('data'))->pluck('email');

        $this->assertTrue($emails->contains($this->tenant['owner']->email));
        $this->assertTrue($emails->contains($this->tenant['staff']->email));

        // The tenant's own client login is not a team member.
        $this->assertFalse($emails->contains($this->tenant['clientUser']->email));

        // Nothing from the other tenant.
        $this->assertFalse($emails->contains($other['owner']->email));
        $this->assertFalse($emails->contains($other['staff']->email));

        // Every row carries the source discriminator.
        foreach ($response->json('data') as $row) {
            $this->assertContains($row['source'], ['users', 'team_invitations']);
        }

        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_pending_invitations_are_merged_in_with_the_invite_source(): void
    {
        TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['email' => 'pending@example.test', 'role' => 'staff']);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/team/members')->assertOk()
            ->assertJsonPath('meta.total', 3);

        $invite = collect($response->json('data'))
            ->firstWhere('email', 'pending@example.test');

        $this->assertNotNull($invite);
        $this->assertSame('team_invitations', $invite['source']);
        $this->assertSame('pending', $invite['status']);
        $this->assertNull($invite['name']);
    }

    public function test_admin_role_members_appear_in_the_list_and_are_filterable(): void
    {
        $admin = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'email' => 'anadmin@example.test',
        ]);
        $admin->assignRole(Role::Admin->value);

        Sanctum::actingAs($this->tenant['owner']);

        // Unfiltered list includes the admin (regression: the provider-side
        // role whitelist used to be owner+staff only, silently dropping admin).
        $all = $this->getJson('/api/v1/team/members')->assertOk();
        $adminRow = collect($all->json('data'))->firstWhere('email', 'anadmin@example.test');
        $this->assertNotNull($adminRow);
        $this->assertSame('admin', $adminRow['role']);
        $this->assertSame('users', $adminRow['source']);

        // ?filter=admin narrows to just the admin.
        $filtered = $this->getJson('/api/v1/team/members?filter=admin')->assertOk();
        $emails = collect($filtered->json('data'))->pluck('email');
        $this->assertTrue($emails->contains('anadmin@example.test'));
        $this->assertFalse($emails->contains($this->tenant['staff']->email));
        $this->assertFalse($emails->contains($this->tenant['owner']->email));
    }

    public function test_the_list_is_paginated(): void
    {
        // Scenario already gives owner + staff (2). Add 18 more staff → 20.
        User::factory()->count(18)->create([
            'provider_id' => $this->tenant['provider']->id,
        ])->each(fn (User $u) => $u->assignRole(Role::Staff->value));

        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson('/api/v1/team/members')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/team/members?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }
}
