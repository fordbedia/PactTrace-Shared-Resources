<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Http\UploadedFile;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Upload\DocumentUploadService;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * How an uploaded file becomes a storage key. The service owns exactly one
 * decision — the shape of that key — so that is what these assert, against an
 * in-memory DocumentStorage rather than a real disk: this class's job is to
 * *call* the port correctly, and testing it through S3/Storage would be
 * testing the adapter instead (S3DocumentStorageTest covers that end).
 */
class DocumentUploadServiceTest extends BaseTest
{
    private InMemoryDocumentStorage $storage;

    private DocumentUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new InMemoryDocumentStorage();
        $this->service = new DocumentUploadService($this->storage);
    }

    public function test_it_stores_the_file_and_returns_its_path(): void
    {
        $path = $this->service->store(UploadedFile::fake()->create('retainer.pdf', 12), 42);

        $this->assertArrayHasKey($path, $this->storage->files, 'The returned path must be the one written.');
    }

    public function test_the_path_is_namespaced_by_provider(): void
    {
        // Tenant isolation reaches into the bucket layout too — one provider's
        // keys must never be guessable from another's prefix.
        $path = $this->service->store(UploadedFile::fake()->create('retainer.pdf', 1), 7);

        $this->assertStringStartsWith('documents/7/', $path);
    }

    public function test_the_path_keeps_the_original_file_name(): void
    {
        // The stored name is what a download eventually re-serves, and what
        // makes an object recognisable when someone opens the bucket by hand.
        $path = $this->service->store(UploadedFile::fake()->create('Signed Retainer.pdf', 1), 7);

        $this->assertStringEndsWith('-Signed Retainer.pdf', $path);
    }

    public function test_uploading_the_same_file_name_twice_does_not_overwrite(): void
    {
        // A uuid sits between the prefix and the name for exactly this reason:
        // two clients uploading "contract.pdf" must not clobber each other.
        $first = $this->service->store(UploadedFile::fake()->create('contract.pdf', 1), 7);
        $second = $this->service->store(UploadedFile::fake()->create('contract.pdf', 1), 7);

        $this->assertNotSame($first, $second);
        $this->assertCount(2, $this->storage->files);
    }

    public function test_it_writes_the_files_contents(): void
    {
        $file = UploadedFile::fake()->createWithContent('notes.pdf', 'the actual bytes');

        $path = $this->service->store($file, 7);

        $this->assertSame('the actual bytes', $this->storage->files[$path]);
    }

    public function test_it_goes_through_the_port_rather_than_a_disk(): void
    {
        // Guards the hexagonal rule: if someone swaps the port call for a
        // Storage:: facade call, the fake stops recording and this fails.
        $this->service->store(UploadedFile::fake()->create('a.pdf', 1), 7);

        $this->assertSame(1, $this->storage->putCalls);
    }
}

/**
 * A DocumentStorage that keeps everything in an array. Deliberately local to
 * this test file — it exists to observe the port, not to be reused as a
 * general-purpose fake.
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
