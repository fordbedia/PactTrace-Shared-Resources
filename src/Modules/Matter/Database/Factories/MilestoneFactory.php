<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    protected $model = Milestone::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'status' => fake()->randomElement(['pending', 'in_progress', 'completed']),
            'position' => fake()->numberBetween(0, 10),
            'due_date' => fake()->dateTimeBetween('now', '+2 months'),
            'completed_at' => null,
        ];
    }
}
