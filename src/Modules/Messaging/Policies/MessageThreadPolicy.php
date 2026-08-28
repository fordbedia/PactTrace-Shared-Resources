<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Policies;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

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
     * Pass the client the thread is with:
     *
     *     $user->can('create', [MessageThread::class, $client])
     */
    public function create(User $user, ?Client $client = null): bool
    {
        return $this->check($user, Permission::MessageSend, $client);
    }

    public function reply(User $user, MessageThread $thread): bool
    {
        return $this->check($user, Permission::MessageSend, $thread);
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
