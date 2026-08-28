<?php

namespace PactTrackSDK\SharedResources\Modules\Messaging\Models;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PactTrackSDK\SharedResources\Modules\Messaging\Database\Factories\MessageFactory;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'sender_id',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /** Was this message sent by someone other than the given user? */
    public function isFrom(int $userId): bool
    {
        return (int) $this->sender_id === $userId;
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Stamps the message as read, once. A no-op if it already is — so a
     * "mark thread read" sweep can call this over every message without
     * churning `read_at` on the ones already stamped.
     */
    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
