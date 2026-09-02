<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Support;

use InvalidArgumentException;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Action\KeyToIdResolver;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationPreferenceRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Preferences\NotificationPreferenceResolver;
use PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases\ResetNotificationPreferences;
use PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases\UpdateNotificationPreference;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The app-wide helper for notification preferences — both "should we notify?"
 * (read) and "turn this on/off for a user" (write).
 *
 *     use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
 *
 *     // read
 *     if (Notification::isset('unread_message_reminder')) { ... }          // current user, email
 *     if (Notification::isset('new_message_from_client', $someUser)) { ... }
 *
 *     // write
 *     Notification::disable('unread_message_reminder');                    // current user, email
 *     Notification::enable('new_doc_uploaded', $someUser);
 *     Notification::set('signature_completed', ['email' => true, 'sms' => false], $someUser);
 *     Notification::reset($someUser);                                      // back to every default
 *
 * Deliberately NOT aliased to the bare `\Notification` global — that name is
 * Laravel's own Notification facade. Import this class where you need it (or
 * use the `notification_enabled()` / `notification_set()` global functions,
 * which need no import).
 *
 * Naming: `enabled()` (past tense) is a READ — an alias for `isset()`.
 * `enable()` / `disable()` (imperative) are WRITES.
 *
 * Only the `email` channel is surfaced in the product today; `in_app` / `sms`
 * are accepted so callers don't change when those ship.
 */
final class Notification
{
    /* ── read ───────────────────────────────────────────────────────── */

    /**
     * Is this notification type turned on for the user, on the given channel?
     *
     * Returns `false` for an unknown key, and `false` when there is no
     * resolvable user (none passed, nobody authenticated) — a send needs a
     * recipient, so fail safe. A locked channel always reads `true`.
     *
     * @param  'email'|'in_app'|'sms'  $channel
     */
    public static function isset(string $key, ?User $user = null, string $channel = 'email'): bool
    {
        return static::resolver()->isEnabled($key, $user, $channel);
    }

    /** Readable alias for `isset()` — identical behaviour. A READ, not `enable()`. */
    public static function enabled(string $key, ?User $user = null, string $channel = 'email'): bool
    {
        return static::resolver()->isEnabled($key, $user, $channel);
    }

    /** Explicit-channel read, e.g. `Notification::channel('signature_completed', 'sms')`. */
    public static function channel(string $key, string $channel, ?User $user = null): bool
    {
        return static::resolver()->isEnabled($key, $user, $channel);
    }

	public static function getIdByKey(string $key)
	{
		return app(KeyToIdResolver::class)($key);
	}

    /* ── write ──────────────────────────────────────────────────────── */

    /**
     * Turn a notification type ON for a user, on one channel.
     *
     * `$user` defaults to the authenticated user; pass it explicitly from a
     * job/command (there is no "current user" there). A locked channel
     * (`security_alerts`) is a no-op — it can't be changed. An unknown `$key`
     * throws `Illuminate\Database\Eloquent\ModelNotFoundException`.
     *
     * @param  'email'|'in_app'|'sms'  $channel
     * @return bool  the channel's effective state after the call
     */
    public static function enable(string $key, ?User $user = null, string $channel = 'email'): bool
    {
        return static::write($key, [$channel => true], $user)[$channel] ?? false;
    }

    /**
     * Turn a notification type OFF for a user, on one channel. Same rules as
     * `enable()` (locked channel = no-op, unknown key throws).
     *
     * @param  'email'|'in_app'|'sms'  $channel
     * @return bool  the channel's effective state after the call
     */
    public static function disable(string $key, ?User $user = null, string $channel = 'email'): bool
    {
        return static::write($key, [$channel => false], $user)[$channel] ?? false;
    }

    /**
     * Set one or more channels at once.
     *
     *     Notification::set('signature_completed', ['email' => true, 'sms' => false]);
     *
     * A single-channel change never disturbs the others on that row (the
     * override row is seeded from the type defaults first). Locked channels in
     * `$channels` are ignored.
     *
     * @param  array<'email'|'in_app'|'sms', bool>  $channels
     * @return array<string, mixed>  the type's effective row after the write
     */
    public static function set(string $key, array $channels, ?User $user = null): array
    {
        return static::write($key, $channels, $user);
    }

	public static function create(array $data)
	{
		return app(NotificationPreferenceRepository::class)->createForUser($data);
	}

    /**
     * Drop every override for a user — back to each type's defaults. The
     * inverse of any number of `enable()`/`disable()` calls.
     */
    public static function reset(?User $user = null): void
    {
        app(ResetNotificationPreferences::class)->handle(static::resolveUser($user));
    }

    /* ── internals ─────────────────────────────────────────────────── */

    /**
     * @param  array<string, bool>  $channels
     * @return array<string, mixed>
     */
    private static function write(string $key, array $channels, ?User $user): array
    {
        return app(UpdateNotificationPreference::class)->handle(
            static::resolveUser($user),
            $key,
            $channels,
        );
    }

    private static function resolveUser(?User $user): User
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            throw new InvalidArgumentException(
                'Notification write helpers need a target user — pass one explicitly outside an authenticated request.',
            );
        }

        return $user;
    }

    private static function resolver(): NotificationPreferenceResolver
    {
        return app(NotificationPreferenceResolver::class);
    }
}
