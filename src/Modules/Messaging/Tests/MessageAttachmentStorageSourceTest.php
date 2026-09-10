<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Storage\MessageAttachmentStorageSource;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StorageSource;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The `message_attachments` table's contribution to the storage total — the
 * gap this feature closes. Bytes a client attaches to a message occupy real
 * S3 storage and must count against the plan quota.
 *
 * Archived (soft-deleted) threads are still counted (their files remain
 * stored); an attachment that only points at an existing Document is not
 * (those bytes belong to DocumentStorageSource); NULL sizes contribute
 * nothing.
 */
class MessageAttachmentStorageSourceTest extends BaseTest
{
    private MessageAttachmentStorageSource $source;

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = new MessageAttachmentStorageSource();
        $this->tenant = ProviderTenantScenario::make('mass-a');
        $this->other = ProviderTenantScenario::make('mass-b');
    }

    public function test_it_is_a_registered_storage_source(): void
    {
        $this->assertInstanceOf(StorageSource::class, $this->source);
        $this->assertSame('message_attachments', $this->source->key());
    }

    public function test_it_sums_a_providers_attachment_bytes(): void
    {
        $thread = $this->thread($this->tenant);
        $this->attachmentOn($thread, 100);
        $this->attachmentOn($thread, 250);

        $this->assertSame(350, $this->source->sumBytesForProvider($this->tenant['provider']->id));
    }

    public function test_it_never_counts_another_provider(): void
    {
        $this->attachmentOn($this->thread($this->tenant), 100);
        $this->attachmentOn($this->thread($this->other), 9_999);

        $this->assertSame(100, $this->source->sumBytesForProvider($this->tenant['provider']->id));
    }

    public function test_it_counts_attachments_on_an_archived_thread(): void
    {
        $thread = $this->thread($this->tenant);
        $this->attachmentOn($thread, 500);
        $thread->delete(); // archive == soft delete; the file is still in S3

        $this->assertSame(500, $this->source->sumBytesForProvider($this->tenant['provider']->id));
    }

    public function test_it_ignores_a_row_that_only_points_at_a_document(): void
    {
        $message = Message::factory()->create([
            'thread_id' => $this->thread($this->tenant)->id,
            'sender_id' => $this->tenant['owner']->id,
        ]);

        MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'document_id' => $this->tenant['document']->id,
            's3_path' => null,
            'size' => 777,
        ]);

        $this->assertSame(0, $this->source->sumBytesForProvider($this->tenant['provider']->id));
    }

    public function test_a_null_size_contributes_nothing(): void
    {
        $thread = $this->thread($this->tenant);

        $message = Message::factory()->create([
            'thread_id' => $thread->id,
            'sender_id' => $this->tenant['owner']->id,
        ]);
        MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'document_id' => null,
            'size' => null,
        ]);

        $this->attachmentOn($thread, 60);

        $this->assertSame(60, $this->source->sumBytesForProvider($this->tenant['provider']->id));
    }

    private function thread(TestScenarioCollection $tenant): MessageThread
    {
        return MessageThread::factory()
            ->forMatter($tenant['matter'], $tenant['owner'])
            ->create();
    }

    private function attachmentOn(MessageThread $thread, int $size): MessageAttachment
    {
        $message = Message::factory()->create([
            'thread_id' => $thread->id,
            'sender_id' => $thread->staff_user_id,
        ]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'document_id' => null,
            'size' => $size,
        ]);
    }
}
