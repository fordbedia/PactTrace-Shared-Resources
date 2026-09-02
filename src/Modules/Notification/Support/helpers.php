<?php

declare(strict_types=1);

use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Global helper for the Notification module.
 *
 * Required from NotificationProvider::register() rather than declared in
 * composer's `autoload.files`, matching the Workspace module's helpers.php — so
 * adding it does not oblige every consumer to re-dump two autoloaders.
 */
if (! function_exists('notification_enabled')) {
    /**
     * Whether a notification type is on for a user, on a channel.
     *
     *     notification_enabled('new_doc_uploaded')                 // current user, email
     *     notification_enabled('signature_completed', 'sms')       // current user, sms
     *     notification_enabled('new_message_from_client', 'email', $user)
     *
     * Thin pass-through to `Notification::isset()` for callers that would
     * rather not add a `use` line. Returns false for an unknown key or when
     * there is no acting user.
     *
     * @param  'email'|'in_app'|'sms'  $channel
     */
    function notification_enabled(string $key, string $channel = 'email', ?User $user = null): bool
    {
        return Notification::isset($key, $user, $channel);
    }
}

if (! function_exists('notification_set')) {
    /**
     * Turn a notification type on/off for a user, on a channel.
     *
     *     notification_set('unread_message_reminder', false)             // current user, email off
     *     notification_set('new_doc_uploaded', true, 'sms', $user)
     *
     * Thin pass-through to `Notification::enable()` / `Notification::disable()`.
     * A locked channel (`security_alerts`) is a no-op; an unknown key throws.
     * Needs a resolvable user — pass one outside an authenticated request.
     *
     * @param  'email'|'in_app'|'sms'  $channel
     * @return bool  the channel's effective state after the call
     */
    function notification_set(string $key, bool $on, string $channel = 'email', ?User $user = null): bool
    {
        return $on
            ? Notification::enable($key, $user, $channel)
            : Notification::disable($key, $user, $channel);
    }
}
