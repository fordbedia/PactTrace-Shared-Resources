<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\SendMessageAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\SendMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Upload\MessageAttachmentStorageService;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The orchestration behind the New Message modal's Send button. Same style
 * as CreateFolderTest — the action is thin, so the coverage that matters is
 * the contract: one thread per (provider, client, matter), attachments
 * stored through the port, `last_message_at` moved forward, and a
 * NewMessage broadcast fired to *others*.
 */
class SendMessageActionTest extends BaseTest
{
    private SendMessageAction $action;

    private InMemoryDocumentStorage $storage;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new InMemoryDocumentStorage();
        $this->action = new SendMessageAction(
            app(MessageRepository::class),
            new MessageAttachmentStorageService($this->storage),
        );
        $this->tenant = ProviderTenantScenario::make('send-msg');

        Event::fake([NewMessage::class]);
    }

    private function data(array $overrides = []): SendMessageData
    {
        return new SendMessageData(
            provider_id: $overrides['provider_id'] ?? $this->tenant['provider']->id,
            sender_id: $overrides['sender_id'] ?? $this->tenant['owner']->id,
            client_id: $overrides['client_id'] ?? $this->tenant['client']->id,
            matter_id: $overrides['matter_id'] ?? null,
            subject: $overrides['subject'] ?? 'Intro',
            body: $overrides['body'] ?? 'Hello, sending the first note.',
            attachments: $overrides['attachments'] ?? [],
        );
    }

    public function test_it_opens_a_thread_and_persists_the_message(): void
    {
        $message = $this->action->handle($this->data());

        $this->assertInstanceOf(Message::class, $message);
        $this->assertDatabaseHas('message_threads', [
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => null,
            'subject' => 'Intro',
        ]);
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'sender_id' => $this->tenant['owner']->id,
            'body' => 'Hello, sending the first note.',
        ]);
    }

    public function test_a_second_message_to_the_same_client_reuses_the_thread(): void
    {
        $first = $this->action->handle($this->data(['body' => 'one']));
        $second = $this->action->handle($this->data(['body' => 'two']));

        $this->assertSame($first->thread_id, $second->thread_id);
        $this->assertSame(1, MessageThread::query()->count());
        $this->assertSame(2, Message::query()->count());
    }

    public function test_it_moves_last_message_at_forward(): void
    {
        $thread = MessageThread::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => null,
            'last_message_at' => now()->subDays(3),
        ]);

        $message = $this->action->handle($this->data());

        $this->assertSame($thread->id, $message->thread_id);
        $this->assertTrue(
            $thread->refresh()->last_message_at->greaterThan(now()->subMinute()),
            'last_message_at should have been bumped to ~now',
        );
    }

    public function test_it_stores_each_attachment_through_the_storage_port(): void
    {
        $message = $this->action->handle($this->data([
            'attachments' => [
                UploadedFile::fake()->create('agenda.pdf', 10),
                UploadedFile::fake()->create('notes.txt', 4),
            ],
        ]));

        $this->assertSame(2, $this->storage->putCalls);
        $this->assertCount(2, $message->attachments);
        foreach ($message->attachments as $attachment) {
            $this->assertStringStartsWith(
                'message-attachments/' . $this->tenant['provider']->id . '/',
                $attachment->s3_path,
            );
            $this->assertArrayHasKey($attachment->s3_path, $this->storage->files);
        }
    }

    public function test_it_broadcasts_new_message_to_others(): void
    {
        $message = $this->action->handle($this->data());

        Event::assertDispatched(NewMessage::class, function (NewMessage $event) use ($message) {
            return $event->message->is($message)
                && $event->broadcastOn()[0]->name === 'private-messages.thread.' . $message->thread_id;
        });
    }
}

/**
 * A DocumentStorage that keeps everything in an array — local to this test,
 * same shape as the one in DocumentUploadServiceTest, so SendMessageAction's
 * attachment path is exercised without a real disk.
 */
class InMemoryDocumentStorage implements DocumentStorage
{
    /** @var array<string, string> */
    public array $files = [];

    public int $putCalls = 0;

    public function put(string $path, string $contents): void
    {
        $this->putCalls++;
        $this->files[$path] = $contents;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function get(string $path): string
    {
        return $this->files[$path] ?? '';
    }
}
