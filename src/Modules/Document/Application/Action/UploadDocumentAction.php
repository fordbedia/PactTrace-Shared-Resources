<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\Action;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentData;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Upload\DocumentUploadService;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Services\MatterNotificationRecipientResolver;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Services\MilestoneProgressionService;
use PactTrackSDK\SharedResources\Modules\Matter\Domain\ValueObjects\DefaultMilestone;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\NewDocumentUploadedEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Throwable;

/**
 * Orchestration behind the "Upload Documents" modal on /dashboard/documents
 * (see .claude/rules/document.md) — DocumentController::store() calls only
 * this. Deliberately creates a new Document row every time rather than
 * detecting "this is a new version of an existing file" — that's real
 * product logic (match on name + folder? let the user pick explicitly?)
 * that hasn't been decided yet. DocumentVersion exists in the schema for
 * when it is; this action is the natural place to add that branch later.
 */
class UploadDocumentAction
{
    public function __construct(
        private readonly DocumentUploadService $uploadService,
        private readonly DocumentRepository $documents,
        private readonly MilestoneProgressionService $milestoneProgression,
        private readonly MatterNotificationRecipientResolver $recipients,
    ) {
    }

    public function handle(DocumentData $data): Document
    {
        $path = $this->uploadService->store($data->file, $data->provider_id);

        $document = $this->documents->create([
            'provider_id' => $data->provider_id,
            'client_id' => $data->client_id,
            'matter_id' => $data->matter_id,
            'folder_id' => $data->folder_id,
            'uploaded_by' => $data->uploaded_by,
            'name' => $data->file->getClientOriginalName(),
            's3_path' => $path,
            'mime_type' => $data->file->getClientMimeType(),
            'size' => $data->file->getSize(),
            'version' => 1,
        ]);

        // "Drafting" represents a document existing on the matter to work
        // from — a no-op when $data->matter_id is null (a document filed
        // with no Matter, see .claude/rules/matter.md) or once the matter's
        // Drafting milestone is already past `pending`. See
        // .claude/rules/matter.md, "Matter Progress timeline".
        $this->milestoneProgression->completeMilestone($data->matter_id, DefaultMilestone::DRAFTING);

        $this->notifyProviderSideOfClientUpload($document, $data);

        return $document;
    }

    /**
     * Email the matter's assigned staff member (or the provider owner when
     * none is assigned) when a *client* uploads a document — a staff/teammate
     * upload notifying the same staffer back would just be noise, so it's
     * skipped. Gated on the recipient's `new_doc_uploaded` preference. See
     * .claude/rules/notification.md, "Notification::isset() gating at dispatch
     * sites". Best-effort — a mail failure must never fail the upload.
     */
    private function notifyProviderSideOfClientUpload(Document $document, DocumentData $data): void
    {
        try {
            $uploader = User::query()->find($data->uploaded_by);

            if ($uploader === null || ! $uploader->isClientUser()) {
                return;
            }

            $matter = $document->matter()->first();

            $recipient = $matter !== null
                ? $this->recipients->forMatter($matter)
                : $this->recipients->forProvider($data->provider_id);

            if ($recipient === null || ($recipient->email ?? '') === '') {
                return;
            }

            if (! Notification::isset('new_doc_uploaded', $recipient)) {
                return;
            }

            $base = rtrim((string) config('app.frontend_url'), '/');

            Mail::to($recipient->email)->queue(new NewDocumentUploadedEmail(
                recipientName: (string) ($recipient->name ?? 'there'),
                uploaderName: (string) ($uploader->name ?? 'Your client'),
                matterName: (string) ($matter?->name ?? ''),
                documentName: (string) $document->name,
                ctaUrl: $matter?->public_id !== null
                    ? $base . '/dashboard/matters/' . $matter->public_id
                    : $base . '/dashboard/documents',
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
