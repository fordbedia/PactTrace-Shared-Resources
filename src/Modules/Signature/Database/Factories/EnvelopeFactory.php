<?php

namespace PactTraceSDK\SharedResources\Modules\Signature\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;

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
            'provider_envelope_id' => fake()->uuid(),
            'status' => fake()->randomElement(['draft', 'sent', 'viewed', 'signed', 'declined', 'voided']),
            'sent_at' => null,
            'completed_at' => null,
        ];
    }
}
