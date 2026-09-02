<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\SecurityAlertEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Auth\NotifySuccessfulSignIn;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile\ChangeOwnPassword;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Dispatch-site gating for `security_alerts` — see
 * .claude/rules/notification.md, "Notification::isset() gating at dispatch
 * sites". Two events are wired: a successful sign-in
 * (NotifySuccessfulSignIn, which also adds the previously-missing
 * `auth.signed_in` audit-log row) and a password change (ChangeOwnPassword).
 * `security_alerts` is locked on ("Required"), so the gate always passes —
 * the tests below confirm the email still goes out even after an attempt to
 * disable it.
 */
class SecurityAlertNotificationTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = ProviderTenantScenario::make('security-alert');
    }

    public function test_a_successful_sign_in_emails_the_user_and_writes_an_audit_row(): void
    {
        $user = $this->tenant['owner'];

        app(NotifySuccessfulSignIn::class)->handle($user, '203.0.113.7', 'PHPUnit/UA');

        Mail::assertQueued(
            SecurityAlertEmail::class,
            fn (SecurityAlertEmail $mail): bool =>
                $mail->hasTo($user->email)
                && $mail->eventType === 'sign_in'
                && $mail->ipAddress === '203.0.113.7',
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.signed_in',
            'user_id' => $user->id,
            'provider_id' => $user->provider_id,
        ]);
    }

    public function test_sign_in_alert_is_sent_even_when_the_user_tries_to_disable_security_alerts(): void
    {
        $user = $this->tenant['owner'];

        // security_alerts is a locked channel — disable() is a no-op.
        Notification::disable('security_alerts', $user);

        app(NotifySuccessfulSignIn::class)->handle($user, null, null);

        Mail::assertQueued(
            SecurityAlertEmail::class,
            fn (SecurityAlertEmail $mail): bool => $mail->eventType === 'sign_in',
        );
    }

    public function test_a_password_change_emails_the_user(): void
    {
        $user = $this->tenant['owner'];
        $user->password = 'OldPass123!';
        $user->save();

        app(ChangeOwnPassword::class)->handle($user, 'OldPass123!', 'NewPass456!');

        Mail::assertQueued(
            SecurityAlertEmail::class,
            fn (SecurityAlertEmail $mail): bool =>
                $mail->hasTo($user->email)
                && $mail->eventType === 'password_changed',
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'profile.password_changed',
            'user_id' => $user->id,
        ]);
    }

    public function test_password_change_alert_is_sent_even_when_the_user_tries_to_disable_security_alerts(): void
    {
        $user = $this->tenant['owner'];
        $user->password = 'OldPass123!';
        $user->save();

        Notification::disable('security_alerts', $user);

        app(ChangeOwnPassword::class)->handle($user, 'OldPass123!', 'NewPass456!');

        Mail::assertQueued(
            SecurityAlertEmail::class,
            fn (SecurityAlertEmail $mail): bool => $mail->eventType === 'password_changed',
        );
    }
}
