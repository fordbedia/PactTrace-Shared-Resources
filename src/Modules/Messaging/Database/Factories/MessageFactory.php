<?php

namespace PactTrackSDK\SharedResources\Modules\Messaging\Database\Factories;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => MessageThread::factory(),
            'sender_id' => User::factory(),
            'body' => fake()->paragraph(),
            'read_at' => null,
        ];
    }
}
