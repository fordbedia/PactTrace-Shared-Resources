<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * "User X has read/cleared audit_log row Y" — the source of truth behind
 * the notification bell's bold (unread) vs regular (read) text and behind
 * removing an item once it's read or cleared. See the migration's docblock
 * and .claude/rules/notification.md.
 */
class NotificationRead extends Model
{
    protected $fillable = [
        'user_id',
        'audit_log_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class);
    }
}
