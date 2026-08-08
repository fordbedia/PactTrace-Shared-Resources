<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Matter>
 */
class MatterFactory extends Factory
{
    protected $model = Matter::class;

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
            'name' => fake()->catchPhrase(),
            'description' => fake()->optional()->paragraph(),
            'status' => fake()->randomElement(['active', 'completed', 'on_hold', 'cancelled']),
            'start_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'due_date' => fake()->dateTimeBetween('now', '+3 months'),
        ];
    }
}
