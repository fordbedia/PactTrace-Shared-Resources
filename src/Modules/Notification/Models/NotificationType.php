<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry in the notification catalogue — see the
 * `create_notification_types_table` migration and `NotificationTypeSeeder`
 * (which owns the canonical list).
 *
 * Rows are reference data, not user data: the seeder is the source of truth and
 * this table is a projection of it, the same relationship `RolePermissionSeeder`
 * has with the permission tables.
 */
class NotificationType extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'group',
        'position',
        'default_email',
        'default_in_app',
        'default_sms',
        'email_locked',
        'in_app_locked',
        'sms_locked',
    ];

    protected $casts = [
        'position' => 'integer',
        'default_email' => 'boolean',
        'default_in_app' => 'boolean',
        'default_sms' => 'boolean',
        'email_locked' => 'boolean',
        'in_app_locked' => 'boolean',
        'sms_locked' => 'boolean',
    ];

    /** @return HasMany<UserNotification> */
    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    /** Group order, then in-group `position`, then key — the screen's display order. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('group')->orderBy('position')->orderBy('key');
    }

    /** The default for one channel, when a user has no override row. */
    public function defaultFor(string $channel): bool
    {
        return (bool) ($this->{"default_{$channel}"} ?? false);
    }

    /** Whether a channel is locked on (the user may not disable it). */
    public function isLocked(string $channel): bool
    {
        return (bool) ($this->{"{$channel}_locked"} ?? false);
    }
}
