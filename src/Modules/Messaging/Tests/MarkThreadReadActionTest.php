<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Log;
use Mockery;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\MarkThreadReadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use RuntimeException;

/**
 * MarkThreadReadAction backs POST /api/v1/messages/threads/{thread}/read —
 * opening a conversation on /dashboard/messages. Marking the thread read is
 * what drops it out of the "Unread" tab and decrements the sidebar badge;
 * the "inbox updated" broadcast fired afterwards is only a nudge for the
 * user's *other* open tabs.
 *
 * That nudge must never be able to fail the read itself: if the broadcast
 * transport is down (Reverb unreachable, a misconfigured connection), the
 * request would 500, the client's POST /read would hang, and the badge
 * would stay stuck showing an unread total even though the staffer has
 * opened the conversation. This class pins that guarantee.
 *
 * Deliberately does NOT Event::fake(InboxUpdated) — the point is to let the
 * broadcast actually run against a driver that throws.
 */
class MarkThreadReadActionTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('mark-read');
    }

    private function unreadThread(): MessageThread
    {
        $thread = MessageThread::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        $thread->messages()->create([
            'sender_id' => $this->tenant['clientUser']->id,
            'body' => 'client asked something',
            'read_at' => null,
        ]);

        return $thread;
    }

    public function test_it_marks_the_thread_read_for_the_user(): void
    {
        $thread = $this->unreadThread();
        $staffId = (int) $thread->staff_user_id;

        app(MarkThreadReadAction::class)->handle($thread, $staffId);

        $this->assertFalse($thread->fresh()->hasUnreadFor($staffId));
        $this->assertDatabaseMissing('messages', [
            'thread_id' => $thread->id,
            'sender_id' => $this->tenant['clientUser']->id,
            'read_at' => null,
        ]);
    }

    public function test_a_broadcast_transport_failure_does_not_fail_the_read(): void
    {
        // Make the broadcast layer behave exactly like Reverb being
        // unreachable: the dispatch attempt itself throws, synchronously,
        // the moment the event is fired.
        $this->app->instance(BroadcastFactory::class, Mockery::mock(BroadcastFactory::class, function ($mock): void {
            $mock->shouldReceive('queue')->andThrow(new RuntimeException('reverb unreachable'));
            $mock->shouldReceive('connection')->andThrow(new RuntimeException('reverb unreachable'));
        }));
        Log::spy();

        $thread = $this->unreadThread();
        $staffId = (int) $thread->staff_user_id;

        // No exception escapes the action...
        $result = app(MarkThreadReadAction::class)->handle($thread, $staffId);

        // ...and the read still committed.
        $this->assertSame($thread->id, $result->id);
        $this->assertFalse($thread->fresh()->hasUnreadFor($staffId));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'inbox broadcast failed'))
            ->once();
    }
}
