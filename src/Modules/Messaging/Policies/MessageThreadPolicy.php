<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Messaging\Policies;

use PactTraceSDK\SharedResources\Modules\User\Models\User;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTraceSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

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
}
