<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Query;

use Illuminate\Support\Collection;

/**
 * Read model for "who may be assigned to a matter" — the provider-side users
 * (owner + staff) of one provider. Backs the "Assigned Staff" pickers on the
 * New Matter drawer and the Matter Detail page, and the server-side
 * validation that an inbound `assigned_staff_id` really belongs to a
 * provider-side user of the acting tenant (never trusting the request for
 * it — same discipline as the Document module's client/matter derivation).
 *
 * The Messaging module has a superficially similar "provider staff" read
 * model, but that one is provider-wide and client-facing; assignment is a
 * tenant-internal, Matter-bounded concern, so it owns its own port here
 * rather than reaching across the context boundary.
 */
interface AssignableMatterStaff
{
    /**
     * Every provider-side user (the owner first, then staff ordered by
     * name) belonging to the given provider. Client-role users carrying the
     * same `provider_id` are excluded. Each row carries a transient
     * `is_owner` boolean.
     *
     * @return Collection<int, \PactTrackSDK\SharedResources\Modules\User\Models\User>
     */
    public function forProvider(int $providerId): Collection;

    /**
     * Whether the given user id is a provider-side user (owner or staff) of
     * the given provider — the guard applied to every inbound
     * `assigned_staff_id` on matter create/update.
     */
    public function existsForProvider(int $userId, int $providerId): bool;
}
