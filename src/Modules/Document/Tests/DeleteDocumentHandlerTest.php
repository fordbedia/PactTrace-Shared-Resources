<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\DeleteDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Exceptions\DocumentCannotBeDeletedException;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * See .claude/rules/document.md, "Document Deletion & Archival Rules": only
 * a `draft` document may ever be deleted, and that must be enforced here —
 * in the handler — not left to a UI that merely hides the button.
 */
class DeleteDocumentHandlerTest extends BaseTest
{
    private DeleteDocumentHandler $handler;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = app(DeleteDocumentHandler::class);
        $this->tenant = ProviderTenantScenario::make('delete-doc');
    }

    public function test_allows_delete_when_status_is_draft(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Draft);

        $this->handler->handle($document, $this->tenant['owner']);

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_delete_writes_an_audit_log_entry(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Draft);

        $this->handler->handle($document, $this->tenant['owner']);

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $document->provider_id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'document.deleted',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
        ]);
    }

    public function test_rejects_delete_when_status_is_sent(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Sent);

        $this->expectException(DocumentCannotBeDeletedException::class);

        $this->handler->handle($document, $this->tenant['owner']);

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_rejects_delete_when_status_is_partially_signed(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::PartiallySigned);

        $this->expectException(DocumentCannotBeDeletedException::class);

        $this->handler->handle($document, $this->tenant['owner']);
    }

    public function test_rejects_delete_when_status_is_completed(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Completed);

        $this->expectException(DocumentCannotBeDeletedException::class);

        $this->handler->handle($document, $this->tenant['owner']);
    }

    public function test_rejected_delete_writes_no_audit_log_entry(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Sent);

        try {
            $this->handler->handle($document, $this->tenant['owner']);
        } catch (DocumentCannotBeDeletedException) {
            // expected
        }

        $this->assertDatabaseMissing('audit_logs', [
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
        ]);
    }

    private function documentWithStatus(DocumentStatus $status): Document
    {
        return Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => $status,
        ]);
    }
}
