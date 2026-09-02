<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Auth;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\SecurityAlertEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Throwable;

/**
 * Records a successful sign-in: one `auth.signed_in` audit-log row (there was
 * none before — password changes were logged, logins weren't) and the
 * personal "new sign-in to your account" security notice to the user, gated
 * on their `security_alerts` preference. `security_alerts` is locked on
 * ("Required"), so the gate always passes today — it is applied anyway so a
 * future unlock needs no code change here. See
 * .claude/rules/notification.md, "Notification::isset() gating at dispatch
 * sites".
 *
 * Called from SessionController::store() after UserAuthentication::attempt()
 * succeeds — the dispatch belongs in the Application layer, not the
 * controller. The email send is best-effort; a mail failure must never fail
 * the login that already succeeded.
 */
final class NotifySuccessfulSignIn
{
    public function handle(User $actor, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        AuditLog::create([
            'provider_id' => $actor->provider_id,
            'user_id' => $actor->id,
            'action' => 'auth.signed_in',
            'auditable_type' => User::class,
            'auditable_id' => $actor->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ]);

        try {
            if (! Notification::isset('security_alerts', $actor)) {
                return;
            }

            Mail::to((string) $actor->email)->queue(new SecurityAlertEmail(
                recipientName: (string) ($actor->name ?? 'there'),
                eventType: 'sign_in',
                occurredAt: now()->toDayDateTimeString(),
                ipAddress: $ipAddress,
                ctaUrl: rtrim((string) config('app.frontend_url'), '/') . '/profile',
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
