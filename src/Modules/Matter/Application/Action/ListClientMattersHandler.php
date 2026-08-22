<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Action;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * Every matter belonging to one client — backs `/portal`'s "which matter am
 * I looking at" resolution (see .claude/rules/matter.md). Deliberately
 * scoped by `client_id` directly in the query, the same "viewAny checks the
 * permission only, index queries scope themselves" pattern documented on
 * `TenantScopedPolicy` — `MattersController::index()` is NOT reusable here:
 * it scopes only by `provider_id`, which — since the `client` role legitimately
 * holds `Permission::MatterView` (see Role::permissions()) — would let a
 * client-role actor list every matter belonging to every other client of the
 * same provider. That endpoint stays provider-only; this one exists so the
 * portal never has a reason to call it.
 */
class ListClientMattersHandler
{
    public function handle(int $clientId): Collection
    {
        return Matter::query()
            ->where('client_id', $clientId)
            ->with('milestones')
            ->latest()
            ->get();
    }
}
