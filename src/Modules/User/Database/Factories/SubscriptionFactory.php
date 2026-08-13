<?php

namespace PactTraceSDK\SharedResources\Modules\User\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;
use PactTraceSDK\SharedResources\Modules\User\Models\Subscription;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'plan' => fake()->randomElement(['starter', 'professional', 'firm']),
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(14),
            'current_period_ends_at' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'stripe_price_id' => null,
            'canceled_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_ends_at' => now()->addMonth(),
            'stripe_customer_id' => 'cus_'.fake()->bothify('##########'),
            'stripe_subscription_id' => 'sub_'.fake()->bothify('##########'),
            'stripe_price_id' => 'price_'.fake()->bothify('##########'),
        ]);
    }
}
