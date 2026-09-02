<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Http\UploadedFile;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile\UpdateAvatar;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\AvatarStorage;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;

/**
 * The UpdateAvatar use case in isolation, against an in-memory AvatarStorage.
 * The FormRequest (type/size limits) is HTTP-layer and covered by
 * ProfileControllerTest — this checks the store / point / delete-the-old-one
 * sequence.
 */
class UpdateAvatarTest extends BaseTest
{
    private InMemoryAvatarStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new InMemoryAvatarStorage();
        $this->app->instance(AvatarStorage::class, $this->storage);
    }

    private function useCase(): UpdateAvatar
    {
        return $this->app->make(UpdateAvatar::class);
    }

    public function test_it_stores_the_file_and_points_the_user_at_it(): void
    {
        $user = ProviderTenantScenario::make('avatar')['owner'];

        $updated = $this->useCase()->handle($user, UploadedFile::fake()->create('me.png', 4));

        $this->assertNotNull($updated->avatar_path);
        $this->assertStringStartsWith("avatars/{$user->id}/", $updated->avatar_path);
        $this->assertStringEndsWith('-me.png', $updated->avatar_path);
        $this->assertArrayHasKey($updated->avatar_path, $this->storage->files);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'avatar_path' => $updated->avatar_path,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'profile.avatar_updated',
        ]);
    }

    public function test_re_uploading_deletes_the_previous_file(): void
    {
        $user = ProviderTenantScenario::make('avatar')['owner'];

        $first = $this->useCase()->handle($user, UploadedFile::fake()->create('first.png', 1))->avatar_path;
        $second = $this->useCase()->handle($user->refresh(), UploadedFile::fake()->create('second.png', 1))->avatar_path;

        $this->assertNotSame($first, $second);
        $this->assertArrayNotHasKey($first, $this->storage->files, 'The old file must be removed.');
        $this->assertArrayHasKey($second, $this->storage->files);
    }

    public function test_a_first_upload_deletes_nothing(): void
    {
        $user = ProviderTenantScenario::make('avatar')['owner'];

        $this->useCase()->handle($user, UploadedFile::fake()->create('only.png', 1));

        $this->assertSame([], $this->storage->deleted);
    }
}

/** @internal test double */
class InMemoryAvatarStorage implements AvatarStorage
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var list<string> */
    public array $deleted = [];

    public function put(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
        $this->deleted[] = $path;
    }

    public function url(string $path): string
    {
        return 'https://cdn.test/' . $path;
    }
}
