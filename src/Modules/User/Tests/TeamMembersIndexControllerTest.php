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

    public function test_it_lists_this_tenants_staff_and_excludes_the_owner_row(): void
    {
        // A second, unrelated tenant whose members must never appear.
        $other = ProviderTenantScenario::make('team-index-other');

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/team/members')->assertOk();

        $emails = collect($response->json('data'))->pluck('email');

        // The provider owner is never a roster row — for any viewer, the owner
        // included. They manage their own account on /profile.
        $this->assertFalse($emails->contains($this->tenant['owner']->email));
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

        $response->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_the_owner_row_is_absent_even_with_filter_owner(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $emails = collect($this->getJson('/api/v1/team/members?filter=owner')->assertOk()->json('data'))
            ->pluck('email');

        $this->assertFalse($emails->contains($this->tenant['owner']->email));
    }

    public function test_pending_invitations_are_merged_in_with_the_invite_source(): void
    {
        TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['email' => 'pending@example.test', 'role' => 'staff']);

        Sanctum::actingAs($this->tenant['owner']);

        // Owner excluded → 1 staff + 1 invitation.
        $response = $this->getJson('/api/v1/team/members')->assertOk()
            ->assertJsonPath('meta.total', 2);

        $invite = collect($response->json('data'))
            ->firstWhere('email', 'pending@example.test');

        $this->assertNotNull($invite);
        $this->assertSame('team_invitations', $invite['source']);
        $this->assertSame('pending', $invite['status']);
        $this->assertNull($invite['name']);
    }

    public function test_admin_role_members_appear_for_an_owner_and_are_filterable(): void
    {
        $admin = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'email' => 'anadmin@example.test',
        ]);
        $admin->assignRole(Role::Admin->value);

        Sanctum::actingAs($this->tenant['owner']);

        // Unfiltered list (owner's view) includes the admin, never the owner.
        $all = $this->getJson('/api/v1/team/members')->assertOk();
        $adminRow = collect($all->json('data'))->firstWhere('email', 'anadmin@example.test');
        $this->assertNotNull($adminRow);
        $this->assertSame('admin', $adminRow['role']);
        $this->assertSame('users', $adminRow['source']);
        $this->assertFalse(
            collect($all->json('data'))->pluck('email')->contains($this->tenant['owner']->email),
        );

        // ?filter=admin narrows to just the admin.
        $filtered = $this->getJson('/api/v1/team/members?filter=admin')->assertOk();
        $emails = collect($filtered->json('data'))->pluck('email');
        $this->assertTrue($emails->contains('anadmin@example.test'));
        $this->assertFalse($emails->contains($this->tenant['staff']->email));
    }

    public function test_an_admin_caller_only_ever_sees_staff_members(): void
    {
        $admin = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'email' => 'viewing-admin@example.test',
        ]);
        $admin->assignRole(Role::Admin->value);

        $otherAdmin = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'email' => 'peer-admin@example.test',
        ]);
        $otherAdmin->assignRole(Role::Admin->value);

        Sanctum::actingAs($admin);

        // The owner and every other Admin are invisible to an Admin caller no
        // matter what `?filter=` asks for. A non-staff `?filter=` just comes
        // back empty (the roster is already clamped to Staff before the filter
        // runs) — it can never widen the set.
        foreach (['', '?filter=admin', '?filter=owner', '?filter=staff'] as $qs) {
            $emails = collect($this->getJson("/api/v1/team/members{$qs}")->assertOk()->json('data'))
                ->pluck('email');

            $this->assertFalse($emails->contains($this->tenant['owner']->email), "owner hidden for '{$qs}'");
            $this->assertFalse($emails->contains('peer-admin@example.test'), "peer admin hidden for '{$qs}'");
            $this->assertFalse($emails->contains('viewing-admin@example.test'), "self hidden for '{$qs}'");
        }

        // Staff themselves are visible on the unfiltered and staff-filtered views.
        foreach (['', '?filter=staff'] as $qs) {
            $emails = collect($this->getJson("/api/v1/team/members{$qs}")->assertOk()->json('data'))
                ->pluck('email');
            $this->assertTrue($emails->contains($this->tenant['staff']->email), "staff visible for '{$qs}'");
        }

        // A non-staff filter yields nothing at all for an Admin.
        $this->getJson('/api/v1/team/members?filter=admin')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_archived_tab_returns_only_deactivated_members_and_no_invitations(): void
    {
        TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['email' => 'pending-archived@example.test', 'role' => 'staff']);

        $gone = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'email' => 'gone@example.test',
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);
        $gone->assignRole(Role::Staff->value);

        Sanctum::actingAs($this->tenant['owner']);

        $active = collect($this->getJson('/api/v1/team/members?status=active')->assertOk()->json('data'))
            ->pluck('email');
        $this->assertFalse($active->contains('gone@example.test'));
        $this->assertTrue($active->contains($this->tenant['staff']->email));

        $archived = $this->getJson('/api/v1/team/members?status=archived')->assertOk();
        $archivedEmails = collect($archived->json('data'))->pluck('email');
        $this->assertTrue($archivedEmails->contains('gone@example.test'));
        $this->assertFalse($archivedEmails->contains($this->tenant['staff']->email));
        // An invitation was never an active member — nothing to show under archived.
        $this->assertFalse($archivedEmails->contains('pending-archived@example.test'));
    }

    public function test_the_list_is_paginated(): void
    {
        // Scenario gives owner + 1 staff; the owner is excluded, so 1 staff.
        // Add 18 more staff → 19 rows on the active tab.
        User::factory()->count(18)->create([
            'provider_id' => $this->tenant['provider']->id,
        ])->each(fn (User $u) => $u->assignRole(Role::Staff->value));

        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson('/api/v1/team/members')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 19)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/team/members?page=2')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }
}
