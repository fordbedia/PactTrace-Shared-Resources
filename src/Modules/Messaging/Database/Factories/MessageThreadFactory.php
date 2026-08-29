<?php

namespace PactTrackSDK\SharedResources\Modules\Messaging\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<MessageThread>
 */
class MessageThreadFactory extends Factory
{
    protected $model = MessageThread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // matter_id / staff_user_id / subject are all required now (see the
        // reshape migration). The bare factory mints disconnected fixtures;
        // realistic tests pass explicit ids or use ->forMatter().
        return [
            'provider_id' => Provider::factory(),
            'client_id' => Client::factory(),
            'staff_user_id' => User::factory(),
            'matter_id' => Matter::factory(),
            'subject' => fake()->sentence(4),
            'last_message_at' => now(),
        ];
    }

    /**
     * A thread wired consistently to one matter: provider_id and client_id
     * copied from the matter, staff_user_id from the given user.
     */
    public function forMatter(Matter $matter, User $staff, ?string $subject = null): static
    {
        return $this->state(fn (): array => [
            'provider_id' => $matter->provider_id,
            'client_id' => $matter->client_id,
            'matter_id' => $matter->id,
            'staff_user_id' => $staff->id,
            'subject' => $subject ?? fake()->sentence(4),
        ]);
    }
}
