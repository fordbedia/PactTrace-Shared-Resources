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
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * A conversation about one matter between exactly ONE staff member and the
 * matter's client. This is a hard product rule — PactTrack does not support
 * group message threads (see .claude/rules/messaging.md):
 *
 *  - `matter_id` is required. Every thread belongs to exactly one matter;
 *    there is no "client, no matter" case for messaging (unlike Documents).
 *  - `client_id` is DERIVED from `matter.client_id` by the Application
 *    layer — a Matter belongsTo exactly one Client — and is never taken
 *    from request input.
 *  - `staff_user_id` is the single staff member the client is conversing
 *    with. It is the identity every reply is authorised against
 *    (MessageThreadPolicy::reply) — not a "created by" audit field. If a
 *    second staffer wants to talk to the same client about the same
 *    matter, that is a second thread, never multiple staff on one thread.
 *  - `subject` is required and is what distinguishes two threads between
 *    the same staffer and client on the same matter. The DB enforces one
 *    thread per (provider_id, matter_id, staff_user_id, subject).
 *
 * `last_message_at` is a denormalized column for cheap inbox sorting — it
 * is kept current by recordActivity() from the Application layer whenever a
 * Message is added, never by a DB trigger.
 *
 * Archiving a thread is a soft delete (`deleted_at`) — the SoftDeletes
 * trait excludes archived threads from the inbox queries automatically, so
 * nothing hand-writes a `whereNull('deleted_at')` clause. The row and its
 * messages are preserved for the audit trail.
 */
class MessageThread extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'provider_id',
        'client_id',
        'staff_user_id',
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

    /**
     * The one staff member on the other end of this conversation — the
     * only user permitted to reply into it from the provider side.
     */
    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
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
     * persists it. Called from AppendMessageToThread right after a Message
     * is created — the one place `last_message_at` is allowed to move.
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
     * stamped is left untouched.
     *
     * `messages.read_at` is a single column, not per-recipient: a thread is
     * exactly one staffer + one client, so "not sent by me and unread" is
     * unambiguous from either side. This is what drops a thread out of the
     * "Unread" tab / portal unread state and decrements the sidebar badge.
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

    /** Threads on one matter (the portal's per-matter messaging widget). */
    public function scopeForMatter(Builder $query, int $matterId): Builder
    {
        return $query->where('matter_id', $matterId);
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
