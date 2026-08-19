<?php

namespace PactTrackSDK\SharedResources\Modules\Messaging\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<MessageThread>
 */
class MessageThreadFactory extends Factory
{
    protected $model = MessageThread::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'client_id' => Client::factory(),
            'matter_id' => null,
            'subject' => fake()->sentence(4),
            'last_message_at' => now(),
        ];
    }
}
