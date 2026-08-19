<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\SignatureField;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<SignatureField>
 */
class SignatureFieldFactory extends Factory
{
    protected $model = SignatureField::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'signer_id' => Signer::factory(),
            'field_type' => fake()->randomElement(['signature', 'initial', 'date', 'text']),
            'page_number' => 1,
            'x_position' => fake()->randomFloat(2, 0, 600),
            'y_position' => fake()->randomFloat(2, 0, 800),
            'width' => 150,
            'height' => 40,
        ];
    }
}
