<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Signer>
 */
class SignerFactory extends Factory
{
    protected $model = Signer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'provider_signer_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'routing_order' => 1,
            'status' => fake()->randomElement(['pending', 'sent', 'viewed', 'signed', 'declined']),
            'signed_at' => null,
        ];
    }
}
