<?php

namespace PactTrackSDK\SharedResources\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Messaging\Database\Factories\MessageThreadFactory;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * A conversation between a provider and one of its clients, optionally
 * scoped to a matter. `last_message_at` is a denormalized column for cheap
 * inbox sorting (see .claude/rules/messaging.md) — it is kept current by
 * recordActivity() from the Application layer whenever a Message is added,
 * never by a DB trigger.
 *
 * Archiving a thread is a soft delete (`deleted_at`) — the SoftDeletes
 * trait excludes archived threads from the "All" and "Unread" inbox queries
 * automatically, so nothing hand-writes a `whereNull('deleted_at')` clause.
 * The row and its messages are preserved for the audit trail.
 */
class MessageThread extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'provider_id',
        'client_id',
        'matter_id',
        'subject',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    protected static function newFactory(): MessageThreadFactory
    {
        return MessageThreadFactory::new();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }

    /**
     * The most recent message on the thread — backs the inbox row's preview
     * line without pulling the whole conversation. Eager-load it on the
     * listing query (`with('latestMessage')`) so the resource stays
     * query-free.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'thread_id')->latestOfMany();
    }

    /**
     * Marks the moment the most recent message landed on this thread and
     * persists it. Called from SendMessageAction right after a Message is
     * created — the one place `last_message_at` is allowed to move.
     */
    public function recordActivity(\DateTimeInterface $at): void
    {
        $this->forceFill(['last_message_at' => $at])->save();
    }

    /**
     * Every message on this thread, oldest first — the order a conversation
     * view renders in.
     */
    public function conversation(): HasMany
    {
        return $this->messages()->oldest();
    }

    /**
     * Archive this thread — a soft delete. Preserves the row and its
     * messages for the audit trail; it simply stops appearing in the inbox.
     */
    public function archive(): void
    {
        $this->delete();
    }

    /**
     * Stamps every not-yet-read message on this thread that the given user
     * did not send, in a single UPDATE. Idempotent — a message already
     * stamped is left untouched. This is what drops a thread out of the
     * "Unread" tab and decrements the sidebar badge.
     */
    public function markReadFor(int $userId): void
    {
        $this->messages()
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Does this thread hold a message the given user has not read (i.e. one
     * they did not send that has no `read_at`)? A per-instance convenience;
     * the listing queries use the `unread_messages_count` withCount alias
     * instead to avoid N+1.
     */
    public function hasUnreadFor(int $userId): bool
    {
        return $this->messages()
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->exists();
    }

    /** Threads belonging to one provider (tenant scoping for index queries). */
    public function scopeForProvider(Builder $query, int $providerId): Builder
    {
        return $query->where('provider_id', $providerId);
    }

    /**
     * Threads that carry at least one message the given user has not read.
     * Backs the "Unread" inbox tab and the sidebar badge count.
     */
    public function scopeWithUnreadFor(Builder $query, int $userId): Builder
    {
        return $query->whereHas('messages', function (Builder $messages) use ($userId): void {
            $messages->where('sender_id', '!=', $userId)->whereNull('read_at');
        });
    }
}
