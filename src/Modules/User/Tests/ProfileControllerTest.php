<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Client\Models\ClientInvitation;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for the `/profile` screen (Dashboard/Your Profile.html):
 *
 *   PATCH  /api/v1/profile                       identity card "Save Changes"
 *   PUT    /api/v1/profile/password              password card "Update Password"
 *   GET    /api/v1/profile/deletion-eligibility  delete-modal pre-flight
 *   DELETE /api/v1/profile                       delete-modal confirmed submit
 *
 * Every action is scoped to the caller themselves — there is no policy, only
 * `auth:sanctum`. The interesting logic is the deletion blocker set
 * (AccountDeletionPolicy) and the name/password confirmation.
 */
class ProfileControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [
            __DIR__ . '/../routes/api.php',
            // Client invitations aren't touched over HTTP here, only counted —
            // no extra route file needed.
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('profile');
    }

    private function owner(): User
    {
        return $this->tenant['owner'];
    }

    // ── auth ─────────────────────────────────────────────────────────────

    public function test_every_endpoint_requires_authentication(): void
    {
        $this->patchJson('/api/v1/profile', [])->assertStatus(401);
        $this->putJson('/api/v1/profile/password', [])->assertStatus(401);
        $this->getJson('/api/v1/profile/deletion-eligibility')->assertStatus(401);
        $this->deleteJson('/api/v1/profile', [])->assertStatus(401);
    }

    // ── PATCH /profile ───────────────────────────────────────────────────

    public function test_it_updates_name_email_and_phone_and_recombines_the_name(): void
    {
        $user = $this->owner();
        $user->forceFill(['name' => 'Old Name', 'email_verified_at' => now()])->save();

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', [
            'first_name' => 'Sarah',
            'last_name' => 'Mitchell',
            'email' => $user->email, // unchanged
            'phone' => '(415) 555-0182',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Sarah Mitchell')
            ->assertJsonPath('data.phone', '(415) 555-0182');

        $user->refresh();
        $this->assertSame('Sarah Mitchell', $user->name);
        // Email untouched -> verification retained.
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'profile.updated',
        ]);
    }

    public function test_changing_the_email_clears_verification(): void
    {
        $user = $this->owner();
        $user->forceFill(['email_verified_at' => now()])->save();

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', [
            'first_name' => 'Sarah',
            'last_name' => 'Mitchell',
            'email' => 'sarah.new@example.com',
            'phone' => null,
        ])->assertOk()->assertJsonPath('data.email_verified_at', null);

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_it_rejects_an_email_already_used_by_another_user(): void
    {
        $user = $this->owner();
        $taken = $this->tenant['staff']->email;

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', [
            'first_name' => 'Sarah',
            'last_name' => 'Mitchell',
            'email' => $taken,
            'phone' => null,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // ── PUT /profile/password ────────────────────────────────────────────

    public function test_it_changes_the_password_when_the_current_one_is_correct(): void
    {
        $user = $this->owner();
        $user->forceFill(['password' => 'current-secret'])->save();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'current-secret',
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertStatus(204);

        $this->assertTrue(Hash::check('NewPassw0rd!', $user->refresh()->password));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'profile.password_changed',
        ]);
    }

    public function test_it_rejects_a_wrong_current_password(): void
    {
        $user = $this->owner();
        $user->forceFill(['password' => 'current-secret'])->save();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'not-it',
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_it_enforces_the_strength_checklist(): void
    {
        $user = $this->owner();
        $user->forceFill(['password' => 'current-secret'])->save();

        Sanctum::actingAs($user);

        // no uppercase, no symbol, too short
        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'current-secret',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    // ── GET /profile/deletion-eligibility ───────────────────────────────

    public function test_a_clean_account_is_eligible_for_deletion(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/profile/deletion-eligibility')
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('blockers', []);
    }

    public function test_an_active_subscription_blocks_deletion(): void
    {
        Subscription::factory()->active()->create([
            'provider_id' => $this->tenant['provider']->id,
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/profile/deletion-eligibility')
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('blockers.0.code', 'active_subscription');
    }

    public function test_a_trialing_subscription_does_not_block_deletion(): void
    {
        Subscription::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'status' => 'trialing',
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/profile/deletion-eligibility')
            ->assertOk()
            ->assertJsonPath('eligible', true);
    }

    public function test_a_document_out_for_signature_blocks_deletion(): void
    {
        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
            'uploaded_by' => $this->owner()->id,
            'status' => DocumentStatus::Sent->value,
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/profile/deletion-eligibility')
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonFragment(['code' => 'pending_documents']);
    }

    public function test_a_pending_team_invitation_blocks_deletion(): void
    {
        TeamInvitation::factory()->forProvider($this->tenant['provider'])->create();

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/profile/deletion-eligibility')
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonFragment(['code' => 'pending_team_invitations']);
    }

    public function test_a_pending_client_invitation_blocks_deletion(): void
    {
        ClientInvitation::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['otherClient']->id,
            'invited_by' => $this->owner()->id,
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/profile/deletion-eligibility')
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonFragment(['code' => 'pending_client_invitations']);
    }

    // ── DELETE /profile ─────────────────────────────────────────────────

    public function test_it_soft_deactivates_the_account_on_a_valid_confirmation(): void
    {
        $user = $this->owner();
        $user->forceFill(['name' => 'Sarah Mitchell', 'password' => 'right-password'])->save();

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/profile', [
            'name' => 'sarah mitchell', // case-insensitive
            'password' => 'right-password',
        ])->assertStatus(204);

        $user->refresh();
        $this->assertSame('deactivated', $user->status);
        $this->assertNotNull($user->deactivated_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'account.self_deleted',
        ]);
    }

    public function test_it_rejects_a_name_that_does_not_match(): void
    {
        $user = $this->owner();
        $user->forceFill(['name' => 'Sarah Mitchell', 'password' => 'right-password'])->save();

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/profile', [
            'name' => 'Someone Else',
            'password' => 'right-password',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame('active', $user->refresh()->status);
    }

    public function test_it_rejects_a_wrong_password(): void
    {
        $user = $this->owner();
        $user->forceFill(['name' => 'Sarah Mitchell', 'password' => 'right-password'])->save();

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/profile', [
            'name' => 'Sarah Mitchell',
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_it_refuses_deletion_while_a_blocker_is_present(): void
    {
        $user = $this->owner();
        $user->forceFill(['name' => 'Sarah Mitchell', 'password' => 'right-password'])->save();

        Subscription::factory()->active()->create([
            'provider_id' => $this->tenant['provider']->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/profile', [
            'name' => 'Sarah Mitchell',
            'password' => 'right-password',
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'blocked')
            ->assertJsonFragment(['code' => 'active_subscription']);

        $this->assertSame('active', $user->refresh()->status);
    }
}
