<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Matter\Domain\ValueObjects\DefaultMilestone;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Covers the "Drafting" half of the Matter Progress fix — see
 * .claude/rules/matter.md and MilestoneProgressionService. Uploading a
 * document onto a matter is the concrete signal this milestone advances on;
 * an upload with no matter must never error trying to advance one.
 */
class DocumentUploadAdvancesMatterMilestoneTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('upload-milestone');
    }

    public function test_uploading_a_document_to_a_matter_advances_its_drafting_milestone(): void
    {
        $drafting = Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => DefaultMilestone::DRAFTING,
            'status' => 'pending',
            'completed_at' => null,
        ]);
        $review = Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => DefaultMilestone::REVIEW,
            'status' => 'pending',
            'completed_at' => null,
        ]);

        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('brief.pdf', 4),
            'matter_id' => $this->tenant['matter']->id,
        ]);

        $response->assertSuccessful();

        $this->assertSame('completed', $drafting->fresh()->status);
        $this->assertNotNull($drafting->fresh()->completed_at);

        // Only Drafting advances on upload — Review has its own trigger
        // (an envelope being sent) and must stay untouched here.
        $this->assertSame('pending', $review->fresh()->status);
    }

    public function test_a_second_upload_to_an_already_drafting_matter_does_not_error_or_reset_completed_at(): void
    {
        $drafting = Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => DefaultMilestone::DRAFTING,
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);
        $originalCompletedAt = $drafting->completed_at;

        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('exhibit-b.pdf', 4),
            'matter_id' => $this->tenant['matter']->id,
        ]);

        $response->assertSuccessful();
        $this->assertTrue($originalCompletedAt->equalTo($drafting->fresh()->completed_at));
    }

    public function test_uploading_a_document_with_no_matter_does_not_error(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('internal-note.pdf', 4),
        ]);

        $response->assertSuccessful();
    }
}
