<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\UploadDocumentAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentData;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\NewDocumentUploadedEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Dispatch-site gating for `new_doc_uploaded` — see
 * .claude/rules/notification.md, "Notification::isset() gating at dispatch
 * sites". The matter's provider-side contact (assigned staff, or owner as
 * fallback) is emailed when a *client* uploads a document; a staff upload
 * never triggers it, and a recipient who turned the notification off gets
 * nothing.
 */
class NewDocumentUploadedNotificationTest extends BaseTest
{
    private const DISK = 'documents-test';

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['filesystems.document_disk' => self::DISK]);
        Mail::fake();

        $this->tenant = ProviderTenantScenario::make('new-doc-notify');
    }

    private function upload(int $uploadedBy, ?int $matterId): void
    {
        app(UploadDocumentAction::class)->handle(new DocumentData(
            file: UploadedFile::fake()->create('brief.pdf', 4),
            provider_id: (int) $this->tenant['provider']->id,
            uploaded_by: $uploadedBy,
            matter_id: $matterId,
            client_id: (int) $this->tenant['client']->id,
            folder_id: null,
        ));
    }

    public function test_a_client_upload_emails_the_provider_owner_when_no_staff_is_assigned(): void
    {
        $this->upload((int) $this->tenant['clientUser']->id, (int) $this->tenant['matter']->id);

        Mail::assertQueued(
            NewDocumentUploadedEmail::class,
            fn (NewDocumentUploadedEmail $mail): bool =>
                $mail->hasTo($this->tenant['owner']->email)
                && $mail->uploaderName === $this->tenant['clientUser']->name,
        );
    }

    public function test_a_client_upload_emails_the_assigned_staff_member_when_one_is_set(): void
    {
        $this->tenant['matter']->forceFill(['assigned_staff_id' => $this->tenant['staff']->id])->save();

        $this->upload((int) $this->tenant['clientUser']->id, (int) $this->tenant['matter']->id);

        Mail::assertQueued(
            NewDocumentUploadedEmail::class,
            fn (NewDocumentUploadedEmail $mail): bool => $mail->hasTo($this->tenant['staff']->email),
        );
    }

    public function test_a_staff_upload_does_not_notify_anyone(): void
    {
        $this->upload((int) $this->tenant['owner']->id, (int) $this->tenant['matter']->id);

        Mail::assertNotQueued(NewDocumentUploadedEmail::class);
    }

    public function test_no_email_when_the_recipient_disabled_new_doc_uploaded(): void
    {
        Notification::disable('new_doc_uploaded', $this->tenant['owner']);

        $this->upload((int) $this->tenant['clientUser']->id, (int) $this->tenant['matter']->id);

        Mail::assertNotQueued(NewDocumentUploadedEmail::class);
    }

    public function test_a_client_upload_with_no_matter_still_emails_the_owner(): void
    {
        $this->upload((int) $this->tenant['clientUser']->id, null);

        Mail::assertQueued(
            NewDocumentUploadedEmail::class,
            fn (NewDocumentUploadedEmail $mail): bool => $mail->hasTo($this->tenant['owner']->email),
        );
    }
}
