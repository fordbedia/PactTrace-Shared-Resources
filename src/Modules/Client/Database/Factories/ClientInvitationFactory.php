<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Database\Factories;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Client\Models\ClientInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<ClientInvitation>
 */
class ClientInvitationFactory extends Factory
{
    protected $model = ClientInvitation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'client_id' => Client::factory(),
            'invited_by' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }
}
