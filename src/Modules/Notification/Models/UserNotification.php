<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * A user's override for one notification type. Absent = the type's defaults
 * apply. See the `create_user_notifications_table` migration.
 */
class UserNotification extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type_id',
        'email',
        'in_app',
        'sms',
    ];

    protected $casts = [
        'email' => 'boolean',
        'in_app' => 'boolean',
        'sms' => 'boolean',
    ];

    /** @return BelongsTo<User, UserNotification> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<NotificationType, UserNotification> */
    public function notificationType(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class);
    }
}
