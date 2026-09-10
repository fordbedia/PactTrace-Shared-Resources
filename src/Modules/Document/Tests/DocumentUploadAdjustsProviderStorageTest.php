<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\DeleteDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The cached `providers.storage_used_bytes` column must move by exactly the
 * file's size on upload, and back by the same amount when a draft document is
 * deleted — the write-time half of the storage-tracking feature.
 */
class DocumentUploadAdjustsProviderStorageTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private const DISK = 'documents-test';

    private TestScenarioCollection $tenant;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['filesystems.document_disk' => self::DISK]);

        $this->tenant = ProviderTenantScenario::make('doc-storage-cache');

        // The scenario seeds a couple of documents of random size; reset the
        // cache + rows to a known baseline so every assertion is about what
        // this test uploads.
        Document::query()->delete();
        $this->tenant['provider']->forceFill(['storage_used_bytes' => 0])->save();
    }

    public function test_uploading_a_document_increments_the_cached_total_by_its_size(): void
    {
        $response = $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', ['file' => UploadedFile::fake()->create('retainer.pdf', 5)])
            ->assertSuccessful();

        $document = Document::query()->findOrFail($response->json('data.id'));

        $this->assertSame((int) $document->size, $this->used());
    }

    public function test_two_uploads_accumulate(): void
    {
        $first = $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', ['file' => UploadedFile::fake()->create('a.pdf', 3)])
            ->json('data.id');
        $second = $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', ['file' => UploadedFile::fake()->create('b.pdf', 7)])
            ->json('data.id');

        $expected = (int) Document::query()->whereIn('id', [$first, $second])->sum('size');

        $this->assertSame($expected, $this->used());
        $this->assertGreaterThan(0, $expected);
    }

    public function test_deleting_a_draft_document_decrements_the_cached_total(): void
    {
        $id = $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', ['file' => UploadedFile::fake()->create('gone.pdf', 4)])
            ->json('data.id');

        $document = Document::query()->findOrFail($id);
        $this->assertSame((int) $document->size, $this->used());

        app(DeleteDocumentHandler::class)->handle($document, $this->tenant['owner']);

        $this->assertSame(0, $this->used());
    }

    private function used(): int
    {
        return (int) $this->tenant['provider']->fresh()->storage_used_bytes;
    }
}
