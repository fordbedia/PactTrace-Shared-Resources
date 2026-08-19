<?php

namespace PactTrackSDK\SharedResources\Modules\User\Database\Factories;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Provider>
 */
class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'business_name' => fake()->company(),
            'subdomain' => fake()->unique()->slug(2),
            'custom_domain' => null,
            'logo_path' => null,
            'primary_color' => fake()->hexColor(),
            'secondary_color' => fake()->hexColor(),
            'plan' => fake()->randomElement(['starter', 'professional', 'firm']),
            'trial_ends_at' => now()->addDays(14),
        ];
    }
}
