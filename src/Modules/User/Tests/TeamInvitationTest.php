<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\AcceptTeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\InviteTeamMember;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\ResendTeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\TeamInvitationNotAcceptableException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Mail\TeamInvitationEmailNotification;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use RuntimeException;

/**
 * The team-invitation model, repository, and both use cases.
 *
 * The load-bearing property: inviting creates a `team_invitations` row and
 * NEVER a `users` row (that was the bug — `users.password` is NOT NULL, and a
 * half-real user row is worse than none). The real `users` row appears only
 * when AcceptTeamInvitation redeems a valid token.
 */
class TeamInvitationTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = ProviderTenantScenario::make('team-invite');
    }

    /* ── Model ──────────────────────────────────────────────────────────── */

    public function test_pending_invitation_is_neither_expired_nor_accepted(): void
    {
        $invitation = TeamInvitation::factory()->forProvider($this->tenant['provider'])->create();

        $this->assertFalse($invitation->isExpired());
        $this->assertFalse($invitation->isAccepted());
        $this->assertTrue($invitation->isPending());
    }

    public function test_expired_invitation_reports_expired_and_not_pending(): void
    {
        $invitation = TeamInvitation::factory()->forProvider($this->tenant['provider'])->expired()->create();

        $this->assertTrue($invitation->isExpired());
        $this->assertFalse($invitation->isAccepted());
        $this->assertFalse($invitation->isPending());
    }

    public function test_accepted_invitation_reports_accepted_and_not_pending(): void
    {
        $invitation = TeamInvitation::factory()->forProvider($this->tenant['provider'])->accepted()->create();

        $this->assertFalse($invitation->isExpired());
        $this->assertTrue($invitation->isAccepted());
        $this->assertFalse($invitation->isPending());
    }

    public function test_role_is_cast_to_the_role_enum(): void
    {
        $invitation = TeamInvitation::factory()->forProvider($this->tenant['provider'])->role('staff')->create();

        $this->assertSame(Role::Staff, $invitation->role);
    }

    public function test_unusable_reason_names_why_a_link_is_dead(): void
    {
        $provider = $this->tenant['provider'];

        $this->assertNull(
            TeamInvitation::factory()->forProvider($provider)->create()->unusableReason(),
        );
        $this->assertSame(
            'expired',
            TeamInvitation::factory()->forProvider($provider)->expired()->create()->unusableReason(),
        );
        $this->assertSame(
            'accepted',
            TeamInvitation::factory()->forProvider($provider)->accepted()->create()->unusableReason(),
        );

        // Both true → "already used" is the more useful thing to say.
        $both = TeamInvitation::factory()->forProvider($provider)->create([
            'expires_at' => now()->subDay(),
            'accepted_at' => now()->subHour(),
        ]);
        $this->assertSame('accepted', $both->unusableReason());
    }

    /* ── Repository ─────────────────────────────────────────────────────── */

    private function repo(): TeamInvitationRepository
    {
        return $this->app->make(TeamInvitationRepository::class);
    }

    public function test_find_by_token_returns_null_for_an_unknown_token(): void
    {
        $this->assertNull($this->repo()->findByToken('does-not-exist'));
    }

    public function test_find_pending_by_email_only_returns_a_genuinely_pending_row(): void
    {
        $provider = $this->tenant['provider'];

        TeamInvitation::factory()->forProvider($provider)->expired()->create(['email' => 'gone@x.test']);
        TeamInvitation::factory()->forProvider($provider)->accepted()->create(['email' => 'used@x.test']);
        $live = TeamInvitation::factory()->forProvider($provider)->create(['email' => 'live@x.test']);

        $this->assertNull($this->repo()->findPendingByEmail('gone@x.test', $provider->id));
        $this->assertNull($this->repo()->findPendingByEmail('used@x.test', $provider->id));
        $this->assertTrue($this->repo()->findPendingByEmail('live@x.test', $provider->id)?->is($live));

        // Scoped to the tenant — another provider's pending row is invisible.
        $other = ProviderTenantScenario::make('team-invite-other');
        TeamInvitation::factory()->forProvider($other['provider'])->create(['email' => 'live@x.test']);
        $this->assertTrue($this->repo()->findPendingByEmail('live@x.test', $provider->id)?->is($live));
    }

    /* ── InviteTeamMember ───────────────────────────────────────────────── */

    private function invite(): InviteTeamMember
    {
        return $this->app->make(InviteTeamMember::class);
    }

    public function test_inviting_creates_a_team_invitation_row_and_no_user_row(): void
    {
        $usersBefore = User::count();

        $invitation = $this->invite()->handle([
            'email' => '  New.Person@Example.TEST ',
            'role' => 'staff',
            'title' => 'Paralegal',
        ], $this->tenant['owner']);

        $this->assertTrue($invitation->exists);
        $this->assertSame('new.person@example.test', $invitation->email); // normalised
        $this->assertSame(Role::Staff, $invitation->role);
        $this->assertSame('Paralegal', $invitation->title);
        $this->assertSame($this->tenant['provider']->id, $invitation->provider_id);
        $this->assertSame($this->tenant['owner']->id, $invitation->invited_by_user_id);
        $this->assertTrue($invitation->isPending());
        $this->assertNotEmpty($invitation->token);

        // The actual fix: nothing was written to `users`.
        $this->assertSame($usersBefore, User::count());
        $this->assertNull(User::where('email', 'new.person@example.test')->first());

        // The invite email goes out, addressed to the normalised address.
        Mail::assertSent(
            TeamInvitationEmailNotification::class,
            fn (TeamInvitationEmailNotification $mail) => $mail->hasTo('new.person@example.test'),
        );
    }

    public function test_inviting_writes_an_audit_row(): void
    {
        $invitation = $this->invite()->handle(
            ['email' => 'auditee@example.test', 'role' => 'staff'],
            $this->tenant['owner'],
        );

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $this->tenant['provider']->id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'user.invited',
            'auditable_type' => TeamInvitation::class,
            'auditable_id' => $invitation->id,
        ]);
    }

    public function test_re_inviting_a_pending_email_resends_on_the_same_row(): void
    {
        $actor = $this->tenant['owner'];

        $first = $this->invite()->handle(['email' => 'dup@example.test', 'role' => 'staff'], $actor);
        $firstToken = $first->token;

        $second = $this->invite()->handle(['email' => 'dup@example.test', 'role' => 'staff'], $actor);

        // Same row, fresh token — never two live links for one address.
        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($firstToken, $second->token);
        $this->assertSame(1, TeamInvitation::where('email', 'dup@example.test')->count());
    }

    /* ── AcceptTeamInvitation ───────────────────────────────────────────── */

    private function accept(): AcceptTeamInvitation
    {
        return $this->app->make(AcceptTeamInvitation::class);
    }

    private function pendingInvitation(array $overrides = []): TeamInvitation
    {
        return TeamInvitation::factory()
            ->forProvider($this->tenant['provider'])
            ->create(array_merge([
                'email' => 'joiner@example.test',
                'role' => 'staff',
                'title' => 'Associate',
                'invited_by_user_id' => $this->tenant['owner']->id,
            ], $overrides));
    }

    public function test_accepting_a_valid_token_creates_the_login_from_the_invitation(): void
    {
        $invitation = $this->pendingInvitation();

        $user = $this->accept()->handle($invitation->token, 'Dana Joiner', 'a-strong-password');

        $this->assertSame('joiner@example.test', $user->email);
        $this->assertSame('Dana Joiner', $user->name);
        $this->assertSame('Associate', $user->title);
        $this->assertSame(Role::Staff, $user->primaryRole());
        $this->assertSame($this->tenant['provider']->id, (int) $user->provider_id);
        $this->assertTrue(password_verify('a-strong-password', $user->password));

        $this->assertNotNull($invitation->fresh()->accepted_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.joined',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'provider_id' => $this->tenant['provider']->id,
        ]);
    }

    public function test_accepting_an_admin_invite_grants_the_admin_role_and_roster_management_only(): void
    {
        $invitation = $this->pendingInvitation(['role' => 'admin']);

        $user = $this->accept()->handle($invitation->token, 'Adah Admin', 'a-strong-password');

        // Admin is its own first-class role — not staff, not owner.
        $this->assertSame(Role::Admin, $user->primaryRole());
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('staff'));
        $this->assertFalse($user->hasRole('owner'));

        // Everything Staff can do…
        $this->assertTrue($user->hasPermissionTo('client.create'));
        $this->assertTrue($user->hasPermissionTo('matter.create'));
        // …plus roster management…
        $this->assertTrue($user->hasPermissionTo('user.invite'));
        $this->assertTrue($user->hasPermissionTo('user.update'));
        $this->assertTrue($user->hasPermissionTo('user.delete'));
        // …plus workspace create/delete (2026-09-01, per Ed)…
        $this->assertTrue($user->hasPermissionTo('workspace.create'));
        $this->assertTrue($user->hasPermissionTo('workspace.delete'));
        // …but none of the owner-only tenant controls.
        $this->assertFalse($user->hasPermissionTo('provider.manage-billing'));
        $this->assertFalse($user->hasPermissionTo('provider.update'));
    }

    public function test_accepting_a_staff_invite_grants_no_roster_management(): void
    {
        $invitation = $this->pendingInvitation(['role' => 'staff']);

        $user = $this->accept()->handle($invitation->token, 'Sam Staff', 'a-strong-password');

        $this->assertSame(Role::Staff, $user->primaryRole());
        $this->assertFalse($user->hasPermissionTo('user.invite'));
        $this->assertFalse($user->hasPermissionTo('user.update'));
        $this->assertFalse($user->hasPermissionTo('user.delete'));
    }

    public function test_a_token_cannot_be_accepted_twice(): void
    {
        $invitation = $this->pendingInvitation();
        $this->accept()->handle($invitation->token, 'Dana Joiner', 'a-strong-password');

        $this->expectException(RuntimeException::class);
        $this->accept()->handle($invitation->token, 'Impostor', 'another-strong-pass');
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $invitation = $this->pendingInvitation(['expires_at' => now()->subDay()]);

        $this->expectException(RuntimeException::class);
        $this->accept()->handle($invitation->token, 'Too Late', 'a-strong-password');
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->accept()->handle('no-such-token', 'Nobody', 'a-strong-password');
    }

    public function test_a_rejected_accept_leaves_no_orphaned_login(): void
    {
        $invitation = $this->pendingInvitation(['expires_at' => now()->subDay()]);
        $usersBefore = User::count();

        try {
            $this->accept()->handle($invitation->token, 'Too Late', 'a-strong-password');
            $this->fail('Expected the expired token to be rejected.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($usersBefore, User::count());
    }

    public function test_accept_rejection_carries_the_reason(): void
    {
        $expired = $this->pendingInvitation(['expires_at' => now()->subDay()]);

        try {
            $this->accept()->handle($expired->token, 'Nope', 'a-strong-password');
            $this->fail('Expected rejection.');
        } catch (TeamInvitationNotAcceptableException $e) {
            $this->assertSame('expired', $e->reason);
            $this->assertSame(410, $e->httpStatus());
        }

        try {
            $this->accept()->handle('no-such-token', 'Nope', 'a-strong-password');
            $this->fail('Expected rejection.');
        } catch (TeamInvitationNotAcceptableException $e) {
            $this->assertSame('unknown', $e->reason);
            $this->assertSame(404, $e->httpStatus());
        }
    }

    /* ── ResendTeamInvitation ───────────────────────────────────────────── */

    private function resend(): ResendTeamInvitation
    {
        return $this->app->make(ResendTeamInvitation::class);
    }

    public function test_resend_rotates_the_token_and_resets_expiry(): void
    {
        $invitation = $this->pendingInvitation(['expires_at' => now()->addDay()]);
        $oldToken = $invitation->token;
        $oldExpiry = $invitation->expires_at;

        $updated = $this->resend()->handle($invitation, $this->tenant['owner']);

        $this->assertNotSame($oldToken, $updated->token);
        $this->assertTrue($updated->expires_at->greaterThan($oldExpiry));
        $this->assertNull($this->repo()->findByToken($oldToken), 'old token must resolve to nothing');

        Mail::assertSent(
            TeamInvitationEmailNotification::class,
            fn (TeamInvitationEmailNotification $mail) => $mail->hasTo($invitation->email),
        );
    }

    public function test_resend_refuses_an_already_accepted_invitation(): void
    {
        $invitation = $this->pendingInvitation();
        $this->accept()->handle($invitation->token, 'Joined Already', 'a-strong-password');

        Mail::fake();

        try {
            $this->resend()->handle($invitation->fresh(), $this->tenant['owner']);
            $this->fail('Expected an accepted invitation to be un-resendable.');
        } catch (TeamInvitationNotAcceptableException $e) {
            $this->assertSame('accepted', $e->reason);
        }

        Mail::assertNothingSent();
    }
}
