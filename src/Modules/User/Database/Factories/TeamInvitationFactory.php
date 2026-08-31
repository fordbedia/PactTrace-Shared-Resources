<?php

namespace PactTrackSDK\SharedResources\Modules\User\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<TeamInvitation>
 */
class TeamInvitationFactory extends Factory
{
    protected $model = TeamInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'email' => fake()->unique()->safeEmail(),
            'title' => fake()->optional()->jobTitle(),
            'role' => 'staff',
            'invited_by_user_id' => User::factory(),
            'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    public function forProvider(Provider|int $provider): static
    {
        return $this->state(fn (): array => [
            'provider_id' => $provider instanceof Provider ? $provider->id : $provider,
        ]);
    }

    public function role(string $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    /** Past its expiry — findPendingByEmail must skip it, isPending() is false. */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /** Already redeemed — a real `users` row exists, the token is dead. */
    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'accepted_at' => now()->subHour(),
        ]);
    }
}
