<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Policies;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

class EnvelopePolicy extends TenantScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::EnvelopeView);
    }

    public function view(User $user, Envelope $envelope): bool
    {
        return $this->check($user, Permission::EnvelopeView, $envelope);
    }

    /**
     * Pass the document being sent for signature:
     *
     *     $user->can('create', [Envelope::class, $document])
     */
    public function create(User $user, ?Document $document = null): bool
    {
        return $this->check($user, Permission::EnvelopeCreate, $document);
    }

    public function send(User $user, Envelope $envelope): bool
    {
        return $this->check($user, Permission::EnvelopeSend, $envelope);
    }

    /**
     * Only grants the *right* to sign this envelope at all. Whether the actor
     * is actually one of its signers, and whether it is their turn in the
     * routing order, is envelope state rather than authorisation — that check
     * belongs in the signing use case.
     */
    public function sign(User $user, Envelope $envelope): bool
    {
        return $this->check($user, Permission::EnvelopeSign, $envelope);
    }

    public function void(User $user, Envelope $envelope): bool
    {
        return $this->check($user, Permission::EnvelopeVoid, $envelope);
    }
}
