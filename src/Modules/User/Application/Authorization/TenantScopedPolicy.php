<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Application\Authorization;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Base class for every PactTrace policy.
 *
 * Authorising an action here is always two separate questions:
 *
 *   1. Is this actor allowed to perform this kind of action? — the spatie
 *      permission, which comes from their role.
 *   2. Are they allowed to perform it on *this record*? — tenant isolation,
 *      which no permission can express because it depends on the row.
 *
 * Both must pass. Keeping (2) in the base class is the whole point: a policy
 * that only checked the permission would let a provider act on another
 * provider's data, and that is exactly the bug that is easiest to write by
 * accident and worst to ship in a product holding legal documents.
 *
 * For the same reason there is deliberately no `Gate::before` super-admin
 * bypass anywhere in the codebase. An owner already holds every permission in
 * the catalogue, but they hold them *inside their own tenant only*; a
 * `Gate::before` returning true for owners would short-circuit the tenant check
 * below and silently make the whole system single-tenant.
 */
abstract class TenantScopedPolicy
{
    /**
     * Permission plus, when a record is supplied, tenant and client scoping.
     *
     * Call this from every policy method rather than checking the permission
     * directly.
     *
     * Passing no record checks the permission alone, which is all a `viewAny`
     * or a bare `create` can do — there is no row yet to scope against. That
     * makes those gates necessary but never sufficient: a `viewAny` that passes
     * says the actor may list this kind of thing, *not* which ones. Index
     * queries must still be constrained to `provider_id` (and, for client-role
     * users, to their own `client_id`) or the gate will happily authorise a
     * listing that returns another tenant's rows. Where a create has a natural
     * parent, pass it — several policies here accept one for exactly this
     * reason.
     */
    protected function check(User $user, Permission $permission, ?Model $record = null): bool
    {
        if (! $this->hasPermission($user, $permission)) {
            return false;
        }

        if ($record === null) {
            return true;
        }

        return $this->belongsToActorsTenant($user, $record)
            && $this->visibleToActorsClient($user, $record);
    }

    /**
     * The provider (tenant) that owns the record.
     *
     * A Provider is its own tenant; everything else carries a `provider_id`.
     * Override for records that only reach their provider through a parent (a
     * Milestone via its Matter, say) — and guard such overrides with an
     * `instanceof` check, because `check()` is also handed *parent* records on
     * create, which resolve by the rules here rather than the subclass's.
     */
    protected function providerIdFor(Model $record): ?int
    {
        if ($record instanceof Provider) {
            return $record->getKey() === null ? null : (int) $record->getKey();
        }

        $providerId = $record->getAttribute('provider_id');

        return $providerId === null ? null : (int) $providerId;
    }

    /**
     * The client the record concerns, or null if it is provider-internal.
     *
     * A Client is its own client — which is what lets a client-role user read
     * their own record while staying blocked from every other client of the
     * same provider. Everything else carries a `client_id`.
     */
    protected function clientIdFor(Model $record): ?int
    {
        if ($record instanceof Client) {
            return $record->getKey() === null ? null : (int) $record->getKey();
        }

        $clientId = $record->getAttribute('client_id');

        return $clientId === null ? null : (int) $clientId;
    }

    /**
     * Does the actor hold the permission at all?
     *
     * spatie throws when asked about a permission that is not in the database,
     * which happens if the catalogue has not been seeded. Denying is the right
     * answer there — an unseeded database should lock the app down, not throw a
     * 500 from inside an authorisation check.
     */
    protected function hasPermission(User $user, Permission $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission->value);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Tenant isolation: the record must belong to the actor's provider.
     *
     * Fails closed when either side is null, so a user not yet attached to a
     * provider, or a record with no provider, is never authorised.
     */
    protected function belongsToActorsTenant(User $user, Model $record): bool
    {
        $actorProviderId = $user->provider_id === null ? null : (int) $user->provider_id;
        $recordProviderId = $this->providerIdFor($record);

        return $actorProviderId !== null
            && $recordProviderId !== null
            && $actorProviderId === $recordProviderId;
    }

    /**
     * Client isolation: a client-role user only ever sees their own records.
     *
     * Provider-side users (owner/staff) see everything inside their tenant, so
     * this is a no-op for them. For a client user a record with no `client_id`
     * — provider-internal work product — is denied.
     */
    protected function visibleToActorsClient(User $user, Model $record): bool
    {
        if (! $user->isClientUser()) {
            return true;
        }

        $actorClientId = $user->client?->id;
        $recordClientId = $this->clientIdFor($record);

        return $actorClientId !== null
            && $recordClientId !== null
            && (int) $actorClientId === $recordClientId;
    }
}
