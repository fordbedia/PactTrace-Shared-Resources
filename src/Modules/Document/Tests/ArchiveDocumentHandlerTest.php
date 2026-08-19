<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\ArchiveDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * See .claude/rules/document.md, "Document Deletion & Archival Rules":
 * archiving is non-destructive and has no status restriction, unlike
 * deletion (DeleteDocumentHandlerTest).
 */
class ArchiveDocumentHandlerTest extends BaseTest
{
    private ArchiveDocumentHandler $handler;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = app(ArchiveDocumentHandler::class);
        $this->tenant = ProviderTenantScenario::make('archive-doc');
    }

    #[DataProvider('everyStatus')]
    public function test_allows_archive_regardless_of_status(DocumentStatus $status): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => $status,
            'archived_at' => null,
        ]);

        $archived = $this->handler->handle($document, $this->tenant['owner']);

        $this->assertNotNull($archived->archived_at);
        $this->assertSame($status, $archived->status, 'Archiving must not change the document status.');
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => $status->value,
        ]);
    }

    public function test_archive_does_not_soft_delete(): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => DocumentStatus::Draft,
        ]);

        $this->handler->handle($document, $this->tenant['owner']);

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_archive_writes_an_audit_log_entry(): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => DocumentStatus::Completed,
        ]);

        $this->handler->handle($document, $this->tenant['owner']);

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $document->provider_id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'document.archived',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
        ]);
    }

    /**
     * @return array<string, array{DocumentStatus}>
     */
    public static function everyStatus(): array
    {
        return array_combine(
            array_map(fn (DocumentStatus $s) => $s->value, DocumentStatus::cases()),
            array_map(fn (DocumentStatus $s) => [$s], DocumentStatus::cases()),
        );
    }
}
