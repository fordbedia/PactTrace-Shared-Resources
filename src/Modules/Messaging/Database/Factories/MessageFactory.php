<?php

namespace PactTraceSDK\SharedResources\Modules\Messaging\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PactTraceSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTraceSDK\SharedResources\Modules\Messaging\Models\MessageThread;

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
