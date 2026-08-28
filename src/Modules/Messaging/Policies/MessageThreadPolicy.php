<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Policies;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

/**
 * View vs. reply are deliberately different questions on a thread (see
 * .claude/rules/messaging.md, "View vs. reply authorization"):
 *
 *  - view  — any staff user in the thread's provider (with matter-view
 *            access) can read the whole conversation, the same
 *            audit-trail/continuity guarantee that lets any staffer see
 *            any document on a matter. A client sees only their own.
 *  - reply — only the thread's own `staff_user_id` may post from the
 *            provider side; the client may post into their own thread.
 *
 * Both the HTTP endpoints and the broadcast channel authorizer
 * (backend/routes/channels.php) go through THIS class — never a
 * hand-written check that could drift.
 */
class MessageThreadPolicy extends TenantScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::MessageView);
    }

    public function view(User $user, MessageThread $thread): bool
    {
        return $this->check($user, Permission::MessageView, $thread);
    }

    /**
     * Pass the client the thread is with (for staff) or the matter's own
     * client (for a client-initiated thread from the portal directory):
     *
     *     $user->can('create', [MessageThread::class, $client])
     */
    public function create(User $user, ?Client $client = null): bool
    {
        return $this->check($user, Permission::MessageSend, $client);
    }

    /**
     * Reply into an existing thread.
     *
     * `check()` establishes permission + tenant, and for a client-role
     * user also that this is their own thread (client_id scoping) — so a
     * client replying into their own thread is authorised outright. A
     * provider-side user is held to the one further rule that makes
     * "one staff member per thread" real: they must be the thread's
     * designated `staff_user_id`. A different staffer viewing the same
     * thread gets a read-only view, never the ability to send as if they
     * were the client's counterpart.
     */
    public function reply(User $user, MessageThread $thread): bool
    {
        if (! $this->check($user, Permission::MessageSend, $thread)) {
            return false;
        }

        if ($user->isClientUser()) {
            return true;
        }

        return (int) $thread->staff_user_id === (int) $user->id;
    }

    /**
     * Archiving (soft-deleting) a thread is a mutation of the conversation,
     * so it takes the same `message.send` permission as replying — a
     * view-only client role holds neither. Tenant scoping is applied by the
     * base check() against the thread.
     */
    public function archive(User $user, MessageThread $thread): bool
    {
        return $this->check($user, Permission::MessageSend, $thread);
    }
}
