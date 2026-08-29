<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Database\Factories;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'user_id' => User::factory(),
            'action' => fake()->randomElement(['created', 'updated', 'deleted', 'viewed', 'signed']),
            'auditable_type' => null,
            'auditable_id' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'metadata' => [],
        ];
    }

    /**
     * A system-initiated row: no tenant, no actor. These belong to no
     * provider and must never surface in a portal listing.
     */
    public function system(): static
    {
        return $this->state(fn (): array => [
            'provider_id' => null,
            'user_id' => null,
        ]);
    }

    public function forProvider(Provider|int $provider): static
    {
        return $this->state(fn (): array => [
            'provider_id' => $provider instanceof Provider ? $provider->id : $provider,
        ]);
    }

    public function byUser(User|int|null $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user instanceof User ? $user->id : $user,
        ]);
    }

    public function action(string $action): static
    {
        return $this->state(fn (): array => ['action' => $action]);
    }
}
