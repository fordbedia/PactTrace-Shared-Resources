<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\User\Mail\TeamInvitationEmailNotification;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for POST /api/v1/team/invitations/{invitation}/resend
 * (auth:sanctum, `user.invite`, per-invitation throttle).
 *
 * The load-bearing guarantees: token ROTATION (the old link is dead, not just
 * superseded-in-the-table), a clear rejection when there is nothing to resend
 * (already accepted), tenant isolation, and the rate limit.
 */
class ResendTeamInvitationTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('team-resend');
    }

    private function pendingInvitation(array $overrides = []): TeamInvitation
    {
        return TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create(array_merge([
                'email' => 'pending@example.test',
                'role' => 'staff',
                'invited_by_user_id' => $this->tenant['owner']->id,
            ], $overrides));
    }

    public function test_resend_requires_authentication(): void
    {
        $invitation = $this->pendingInvitation();

        $this->postJson("/api/v1/team/invitations/{$invitation->id}/resend")
            ->assertStatus(401);
    }

    public function test_a_staff_user_without_the_permission_is_denied(): void
    {
        $invitation = $this->pendingInvitation();
        $originalToken = $invitation->token;

        Sanctum::actingAs($this->tenant['staff']);

        $this->postJson("/api/v1/team/invitations/{$invitation->id}/resend")
            ->assertStatus(403);

        $this->assertSame($originalToken, $invitation->fresh()->token);
        Mail::assertNothingSent();
    }

    public function test_an_owner_can_resend_a_pending_invite_with_a_fresh_token_and_expiry(): void
    {
        $invitation = $this->pendingInvitation(['expires_at' => now()->addDay()]);
        $originalToken = $invitation->token;
        $originalExpiry = $invitation->expires_at;

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/team/invitations/{$invitation->id}/resend")
            ->assertOk()
            ->assertJsonPath('data.email', 'pending@example.test')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.token');

        $fresh = $invitation->fresh();
        $this->assertNotSame($originalToken, $fresh->token);
        $this->assertTrue($fresh->expires_at->greaterThan($originalExpiry));

        Mail::assertSent(
            TeamInvitationEmailNotification::class,
            fn (TeamInvitationEmailNotification $mail) => $mail->hasTo('pending@example.test'),
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.invited',
            'auditable_type' => TeamInvitation::class,
            'auditable_id' => $invitation->id,
        ]);
    }

    public function test_an_expired_invite_can_still_be_resent(): void
    {
        $invitation = $this->pendingInvitation(['expires_at' => now()->subDay()]);

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/team/invitations/{$invitation->id}/resend")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertTrue($invitation->fresh()->isPending());
    }

    public function test_the_superseded_token_is_dead_on_both_accept_endpoints(): void
    {
        $invitation = $this->pendingInvitation();
        $oldToken = $invitation->token;

        Sanctum::actingAs($this->tenant['owner']);
        $this->postJson("/api/v1/team/invitations/{$invitation->id}/resend")->assertOk();

        // Old token now resolves to nothing at all — fully invalidated.
        $this->getJson("/api/v1/team/invitations/{$oldToken}")
            ->assertStatus(404)
            ->assertJsonPath('reason', 'unknown');

        $this->postJson("/api/v1/team/invitations/{$oldToken}/accept", [
            'name' => 'Stale Link',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertStatus(404)->assertJsonPath('reason', 'unknown');

        // The new token works.
        $newToken = $invitation->fresh()->token;
        $this->getJson("/api/v1/team/invitations/{$newToken}")->assertOk();
    }

    public function test_resending_an_already_accepted_invitation_is_rejected_and_sends_no_email(): void
    {
        $invitation = $this->pendingInvitation();

        // Redeem it for real, then clear the mail spy so the assertion below
        // is unambiguously about the resend attempt.
        $this->postJson("/api/v1/team/invitations/{$invitation->token}/accept", [
            'name' => 'Genuine Joiner',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertOk();

        Mail::fake();
        $consumedToken = $invitation->fresh()->token;

        Sanctum::actingAs($this->tenant['owner']);
        $this->postJson("/api/v1/team/invitations/{$invitation->id}/resend")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'accepted');

        // Token untouched, no second email.
        $this->assertSame($consumedToken, $invitation->fresh()->token);
        Mail::assertNothingSent();
    }

    public function test_cannot_resend_another_tenants_invitation(): void
    {
        $other = ProviderTenantScenario::make('team-resend-other');
        $foreign = TeamInvitation::factory()
            ->forProvider($other['provider'])
            ->create(['invited_by_user_id' => $other['owner']->id]);

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/team/invitations/{$foreign->id}/resend")
            ->assertStatus(404);

        Mail::assertNothingSent();
    }

    public function test_resend_is_rate_limited_per_invitation(): void
    {
        $invitation = $this->pendingInvitation();

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/team/invitations/{$invitation->id}/resend")->assertOk();
        $this->postJson("/api/v1/team/invitations/{$invitation->id}/resend")->assertOk();
        $this->postJson("/api/v1/team/invitations/{$invitation->id}/resend")->assertStatus(429);
    }
}
