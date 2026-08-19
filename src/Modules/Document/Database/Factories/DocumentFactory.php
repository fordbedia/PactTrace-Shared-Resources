<?php

namespace PactTrackSDK\SharedResources\Modules\Document\Database\Factories;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

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
            'folder_id' => null,
            'uploaded_by' => User::factory(),
            'name' => fake()->word() . '.pdf',
            's3_path' => 'documents/' . fake()->uuid() . '.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 5_000_000),
            'version' => 1,
        ];
    }
}
