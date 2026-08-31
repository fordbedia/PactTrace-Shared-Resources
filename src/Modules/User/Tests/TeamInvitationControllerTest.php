<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Mail\TeamInvitationEmailNotification;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for the team-invitation surface:
 *
 *   POST /api/v1/team/members                       (auth:sanctum, user.invite)
 *   GET  /api/v1/team/invitations/{token}           (public)
 *   POST /api/v1/team/invitations/{token}/accept    (public)
 *
 * Registers SanctumServiceProvider and authenticates with Sanctum::actingAs()
 * — BaseTest's shared harness only configures the `web` guard (see the
 * testing-sanctum-guard memo / AuditLogControllerTest).
 */
class TeamInvitationControllerTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('team-http');
    }

    /* ── POST /api/v1/team/members ──────────────────────────────────────── */

    public function test_inviting_requires_authentication(): void
    {
        $this->postJson('/api/v1/team/members', [
            'email' => 'x@example.test',
            'role' => 'staff',
        ])->assertStatus(401);
    }

    public function test_a_staff_user_without_the_permission_is_denied(): void
    {
        Sanctum::actingAs($this->tenant['staff']);

        $this->postJson('/api/v1/team/members', [
            'email' => 'x@example.test',
            'role' => 'staff',
        ])->assertStatus(403);

        $this->assertDatabaseCount('team_invitations', 0);
    }

    public function test_an_owner_can_invite_and_gets_the_invitation_back_without_the_token(): void
    {
        $usersBefore = User::count();

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->postJson('/api/v1/team/members', [
            'email' => 'Newbie@Example.TEST',
            'role' => 'staff',
            'title' => 'Paralegal',
        ])->assertStatus(201);

        $response->assertJsonPath('data.email', 'newbie@example.test')
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.title', 'Paralegal')
            ->assertJsonPath('data.status', 'pending');

        // The secret must never be in a staff-readable response.
        $this->assertArrayNotHasKey('token', $response->json('data'));

        // A team_invitations row, not a users row.
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newbie@example.test',
            'provider_id' => $this->tenant['provider']->id,
            'invited_by_user_id' => $this->tenant['owner']->id,
        ]);
        $this->assertSame($usersBefore, User::count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.invited',
            'auditable_type' => TeamInvitation::class,
        ]);

        Mail::assertSent(
            TeamInvitationEmailNotification::class,
            fn (TeamInvitationEmailNotification $mail) => $mail->hasTo('newbie@example.test'),
        );
    }

    public function test_role_client_is_rejected_by_validation(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/team/members', [
            'email' => 'x@example.test',
            'role' => 'client',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_inviting_an_existing_user_email_is_rejected_by_validation(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/team/members', [
            'email' => $this->tenant['staff']->email,
            'role' => 'staff',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_re_inviting_a_pending_email_does_not_create_a_second_row(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/team/members', ['email' => 'dup@example.test', 'role' => 'staff'])->assertStatus(201);
        $this->postJson('/api/v1/team/members', ['email' => 'dup@example.test', 'role' => 'staff'])->assertStatus(201);

        $this->assertSame(1, TeamInvitation::where('email', 'dup@example.test')->count());
    }

    /* ── GET /api/v1/team/invitations/{token} ───────────────────────────── */

    public function test_invitation_details_are_public_and_404_for_an_unknown_token(): void
    {
        $this->getJson('/api/v1/team/invitations/no-such-token')->assertStatus(404);
    }

    public function test_invitation_details_410_for_an_expired_token(): void
    {
        $invitation = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->expired()
            ->create();

        $this->getJson("/api/v1/team/invitations/{$invitation->token}")->assertStatus(410);
    }

    public function test_invitation_details_render_for_a_valid_token(): void
    {
        $invitation = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['email' => 'look@example.test', 'role' => 'staff']);

        $this->getJson("/api/v1/team/invitations/{$invitation->token}")
            ->assertOk()
            ->assertJsonPath('email', 'look@example.test')
            ->assertJsonPath('role', 'staff')
            ->assertJsonPath('provider_name', $this->tenant['provider']->business_name)
            ->assertJsonMissingPath('token');
    }

    public function test_expired_and_used_links_report_distinct_reasons_on_get(): void
    {
        $expired = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->expired()
            ->create();

        $this->getJson("/api/v1/team/invitations/{$expired->token}")
            ->assertStatus(410)
            ->assertJsonPath('reason', 'expired');

        $used = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->accepted()
            ->create();

        $this->getJson("/api/v1/team/invitations/{$used->token}")
            ->assertStatus(410)
            ->assertJsonPath('reason', 'accepted');

        $this->getJson('/api/v1/team/invitations/never-issued')
            ->assertStatus(404)
            ->assertJsonPath('reason', 'unknown');
    }

    /* ── POST /api/v1/team/invitations/{token}/accept ───────────────────── */

    public function test_accepting_a_valid_token_creates_the_login_and_signs_in(): void
    {
        $invitation = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create([
                'email' => 'accepts@example.test',
                'role' => 'staff',
                'title' => 'Associate',
                'invited_by_user_id' => $this->tenant['owner']->id,
            ]);

        $response = $this->postJson("/api/v1/team/invitations/{$invitation->token}/accept", [
            'name' => 'Alex Accepts',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertOk();

        $response->assertJsonPath('user.email', 'accepts@example.test')
            ->assertJsonPath('user.name', 'Alex Accepts')
            ->assertJsonPath('user.role', 'staff');

        $this->assertDatabaseHas('users', [
            'email' => 'accepts@example.test',
            'title' => 'Associate',
            'provider_id' => $this->tenant['provider']->id,
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_accept_creates_exactly_one_hashed_login_with_the_invited_role(): void
    {
        $invitation = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create([
                'email' => 'one@example.test',
                'role' => 'staff',
                'invited_by_user_id' => $this->tenant['owner']->id,
            ]);

        $before = User::count();

        $this->postJson("/api/v1/team/invitations/{$invitation->token}/accept", [
            'name' => 'Solo Row',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertOk();

        $this->assertSame($before + 1, User::count());

        $user = User::where('email', 'one@example.test')->firstOrFail();

        // Stored hashed, never in the clear.
        $this->assertNotSame('a-strong-password', $user->password);
        $this->assertTrue(password_verify('a-strong-password', $user->password));

        // The role from the invitation, with that role's real permission set —
        // not a blank/default account.
        $this->assertTrue($user->hasRole('staff'));
        $this->assertTrue($user->hasPermissionTo('client.view'));
        $this->assertFalse($user->hasPermissionTo('user.invite'));

        // The invitation is consumed — the same link cannot mint a second row.
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->postJson("/api/v1/team/invitations/{$invitation->token}/accept", [
            'name' => 'Impostor',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertStatus(410)->assertJsonPath('reason', 'accepted');

        $this->assertSame($before + 1, User::count());
    }

    public function test_accepting_an_admin_invite_yields_an_admin_login_with_roster_management_permissions(): void
    {
        $invitation = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create([
                'email' => 'admin@example.test',
                'role' => 'admin',
                'invited_by_user_id' => $this->tenant['owner']->id,
            ]);

        $response = $this->postJson("/api/v1/team/invitations/{$invitation->token}/accept", [
            'name' => 'Adah Admin',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertOk();

        $response->assertJsonPath('user.role', 'admin');

        $permissions = $response->json('user.permissions');
        $this->assertContains('user.invite', $permissions);
        $this->assertContains('user.update', $permissions);
        $this->assertContains('user.delete', $permissions);
        // Not a back-door to the whole tenant.
        $this->assertNotContains('provider.manage-billing', $permissions);
        $this->assertNotContains('workspace.create', $permissions);

        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('owner'));
    }

    public function test_accepting_the_same_token_twice_is_410(): void
    {
        $invitation = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['email' => 'twice@example.test', 'invited_by_user_id' => $this->tenant['owner']->id]);

        $this->postJson("/api/v1/team/invitations/{$invitation->token}/accept", [
            'name' => 'First Time',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertOk();

        $this->postJson("/api/v1/team/invitations/{$invitation->token}/accept", [
            'name' => 'Second Time',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertStatus(410);
    }

    public function test_accepting_an_expired_token_is_410(): void
    {
        $invitation = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->expired()
            ->create(['invited_by_user_id' => $this->tenant['owner']->id]);

        $this->postJson("/api/v1/team/invitations/{$invitation->token}/accept", [
            'name' => 'Too Late',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertStatus(410)->assertJsonPath('reason', 'expired');
    }

    public function test_accept_re_validates_the_token_and_reports_a_distinct_reason(): void
    {
        // Server-side re-check: a used link POSTed straight to accept (no
        // preceding GET) still fails, and says *why*.
        $used = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->accepted()
            ->create(['invited_by_user_id' => $this->tenant['owner']->id]);

        $this->postJson("/api/v1/team/invitations/{$used->token}/accept", [
            'name' => 'Second Comer',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertStatus(410)->assertJsonPath('reason', 'accepted');

        $this->postJson('/api/v1/team/invitations/never-issued/accept', [
            'name' => 'Nobody',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertStatus(404)->assertJsonPath('reason', 'unknown');
    }

    public function test_accept_validates_password_confirmation_and_length(): void
    {
        $invitation = TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['invited_by_user_id' => $this->tenant['owner']->id]);

        $this->postJson("/api/v1/team/invitations/{$invitation->token}/accept", [
            'name' => 'Weak Pass',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
