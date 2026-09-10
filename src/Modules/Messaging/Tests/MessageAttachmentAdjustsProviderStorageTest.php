<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\SendMessageAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\SendMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The actual bug this feature fixes: a client attaching files to a message
 * consumes real S3 storage that never counted against the plan quota. Sending
 * a message with an attachment must now increment
 * `providers.storage_used_bytes`, through the exact same
 * ProviderStorageLedger seam an uploaded document uses.
 */
class MessageAttachmentAdjustsProviderStorageTest extends BaseTest
{
    private const DISK = 'messages-storage-test';

    private SendMessageAction $action;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['filesystems.document_disk' => self::DISK]);

        $this->tenant = ProviderTenantScenario::make('msg-storage');
        $this->tenant['provider']->forceFill(['storage_used_bytes' => 0])->save();

        $this->action = app(SendMessageAction::class);

        // After the fixture is built — a bare Event::fake() also suppresses
        // Eloquent model events (Workspace::creating fills its labels), so it
        // must not wrap ProviderTenantScenario. Scope it to the broadcasts
        // SendMessageAction fires.
        Event::fake([NewMessage::class, InboxUpdated::class]);
    }

    public function test_sending_a_message_with_an_attachment_increments_provider_storage(): void
    {
        $this->send([UploadedFile::fake()->create('brief.pdf', 6)]); // 6 KiB => 6144 bytes

        $this->assertSame(6_144, $this->used());
    }

    public function test_multiple_attachments_sum(): void
    {
        $this->send([
            UploadedFile::fake()->create('a.pdf', 2),
            UploadedFile::fake()->create('b.pdf', 3),
        ]);

        $this->assertSame(5_120, $this->used());
    }

    public function test_a_message_with_no_attachment_does_not_change_provider_storage(): void
    {
        $this->send([]);

        $this->assertSame(0, $this->used());
    }

    private function send(array $attachments): void
    {
        $matter = $this->tenant['matter'];

        $this->action->handle(new SendMessageData(
            provider_id: (int) $matter->provider_id,
            sender_id: (int) $this->tenant['owner']->id,
            staff_user_id: (int) $this->tenant['owner']->id,
            client_id: (int) $matter->client_id,
            matter_id: (int) $matter->id,
            subject: 'Documents for review',
            body: 'See attached.',
            attachments: $attachments,
        ));
    }

    private function used(): int
    {
        return (int) $this->tenant['provider']->fresh()->storage_used_bytes;
    }
}
