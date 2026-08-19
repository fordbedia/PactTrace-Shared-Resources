<?php

namespace PactTrackSDK\SharedResources\Modules\Document\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Folder>
 */
class FolderFactory extends Factory
{
    protected $model = Folder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'client_id' => null,
            'matter_id' => null,
            'parent_id' => null,
            'name' => fake()->word(),
        ];
    }
}
