<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\S3\S3DocumentStorage;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * The DocumentStorage adapter. Exercised against `Storage::fake()` rather
 * than real S3 — the point is not that Flysystem works, it's that this class
 * forwards each port method to *the disk it was constructed with*. The disk
 * being a constructor argument is what lets dev run on `local` while
 * production runs on `s3` (see DocumentProvider), and a hardcoded disk name
 * would be an easy, silent regression: everything would still pass on a
 * machine where both disks exist.
 */
class S3DocumentStorageTest extends BaseTest
{
    private const DISK = 'documents-test';

    private S3DocumentStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);

        $this->storage = new S3DocumentStorage(self::DISK);
    }

    public function test_it_implements_the_port(): void
    {
        $this->assertInstanceOf(DocumentStorage::class, $this->storage);
    }

    public function test_put_writes_to_the_configured_disk(): void
    {
        $this->storage->put('documents/1/retainer.pdf', 'contents');

        Storage::disk(self::DISK)->assertExists('documents/1/retainer.pdf');
        $this->assertSame('contents', Storage::disk(self::DISK)->get('documents/1/retainer.pdf'));
    }

    public function test_get_reads_back_what_was_written(): void
    {
        $this->storage->put('documents/1/notes.txt', 'the actual bytes');

        $this->assertSame('the actual bytes', $this->storage->get('documents/1/notes.txt'));
    }

    public function test_exists_reports_presence(): void
    {
        $this->assertFalse($this->storage->exists('documents/1/missing.pdf'));

        $this->storage->put('documents/1/present.pdf', 'x');

        $this->assertTrue($this->storage->exists('documents/1/present.pdf'));
    }

    public function test_delete_removes_the_object(): void
    {
        $this->storage->put('documents/1/gone.pdf', 'x');

        $this->storage->delete('documents/1/gone.pdf');

        $this->assertFalse($this->storage->exists('documents/1/gone.pdf'));
        Storage::disk(self::DISK)->assertMissing('documents/1/gone.pdf');
    }

    public function test_deleting_something_that_is_not_there_is_not_an_error(): void
    {
        // Cleanup paths (a failed upload, a re-run job) call this without
        // knowing whether the object landed — throwing here would turn a
        // no-op into a 500.
        $this->storage->delete('documents/1/never-existed.pdf');

        $this->assertFalse($this->storage->exists('documents/1/never-existed.pdf'));
    }

    public function test_it_writes_only_to_the_disk_it_was_given(): void
    {
        Storage::fake('other-disk');

        $this->storage->put('documents/1/scoped.pdf', 'x');

        Storage::disk('other-disk')->assertMissing('documents/1/scoped.pdf');
    }

    public function test_a_second_instance_can_target_a_different_disk(): void
    {
        Storage::fake('local-dev-disk');

        (new S3DocumentStorage('local-dev-disk'))->put('documents/1/dev.pdf', 'x');

        Storage::disk('local-dev-disk')->assertExists('documents/1/dev.pdf');
        Storage::disk(self::DISK)->assertMissing('documents/1/dev.pdf');
    }
}
