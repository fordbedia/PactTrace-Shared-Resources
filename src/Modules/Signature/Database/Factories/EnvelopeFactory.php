<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Envelope>
 */
class EnvelopeFactory extends Factory
{
    protected $model = Envelope::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'document_id' => Document::factory(),
            'client_id' => Client::factory(),
            'provider' => 'docusign',
            'provider_envelope_id' => fake()->uuid(),
            'status' => fake()->randomElement(['draft', 'sent', 'viewed', 'partially_signed', 'completed', 'declined', 'voided', 'expired']),
            'sent_at' => null,
            'completed_at' => null,
        ];
    }
}
