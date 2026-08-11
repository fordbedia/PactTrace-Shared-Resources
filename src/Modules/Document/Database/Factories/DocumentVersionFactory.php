<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Database\Factories;

use PactTraceSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Document\Models\DocumentVersion;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'uploaded_by' => User::factory(),
            's3_path' => 'documents/' . fake()->uuid() . '.pdf',
            'version' => fake()->numberBetween(1, 5),
            'size' => fake()->numberBetween(1024, 5_000_000),
        ];
    }
}
