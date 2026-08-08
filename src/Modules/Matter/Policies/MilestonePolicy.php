<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Matter\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

/**
 * Milestones carry no `provider_id` or `client_id` of their own — they inherit
 * both from their matter, so the scoping accessors resolve through it. If the
 * matter cannot be loaded, both return null and the base policy denies.
 *
 * The `instanceof` guards matter: `create()` hands the base a Matter rather
 * than a Milestone, and a Matter already answers both questions directly.
 */
class MilestonePolicy extends TenantScopedPolicy
{
    protected function providerIdFor(Model $record): ?int
    {
        if (! $record instanceof Milestone) {
            return parent::providerIdFor($record);
        }

        $providerId = $record->matter?->provider_id;

        return $providerId === null ? null : (int) $providerId;
    }

    protected function clientIdFor(Model $record): ?int
    {
        if (! $record instanceof Milestone) {
            return parent::clientIdFor($record);
        }

        $clientId = $record->matter?->client_id;

        return $clientId === null ? null : (int) $clientId;
    }

    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::MilestoneView);
    }

    public function view(User $user, Milestone $milestone): bool
    {
        return $this->check($user, Permission::MilestoneView, $milestone);
    }

    /**
     * Pass the matter the milestone belongs to:
     *
     *     $user->can('create', [Milestone::class, $matter])
     */
    public function create(User $user, ?Matter $matter = null): bool
    {
        return $this->check($user, Permission::MilestoneCreate, $matter);
    }

    public function update(User $user, Milestone $milestone): bool
    {
        return $this->check($user, Permission::MilestoneUpdate, $milestone);
    }

    public function delete(User $user, Milestone $milestone): bool
    {
        return $this->check($user, Permission::MilestoneDelete, $milestone);
    }
}
