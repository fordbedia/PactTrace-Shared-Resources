<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * A minimal provider tenant for the dashboard HTTP tests: a Provider and its
 * Owner-role user, nothing else.
 *
 * Deliberately lighter than ProviderTenantScenario — no workspace, so
 * BelongsToWorkspace's global scope stays in its fail-open state and every
 * assertion is about `provider_id` isolation alone, and no pre-seeded
 * matters/documents/envelopes whose random factory statuses would make
 * count assertions non-deterministic. Each test creates exactly the rows it
 * asserts on.
 */
trait BuildsDashboardTenant
{
    /**
     * @return array{0: Provider, 1: User} the provider and its owner
     */
    protected function makeProviderTenant(string $emailPrefix): array
    {
        $owner = User::factory()->create(['email' => "{$emailPrefix}-owner@pacttrack.test"]);
        $provider = Provider::factory()->create(['owner_user_id' => $owner->id]);

        $owner->forceFill(['provider_id' => $provider->id])->save();
        $owner->assignRole(Role::Owner->value);

        return [$provider, $owner->fresh()];
    }

    protected function makeClientUser(Provider $provider, string $emailPrefix): User
    {
        $user = User::factory()->create([
            'email' => "{$emailPrefix}-client@pacttrack.test",
            'provider_id' => $provider->id,
        ]);
        $user->assignRole(Role::Client->value);

        return $user->fresh();
    }
}
