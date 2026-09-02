<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\AvatarStorage;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The `/profile` identity card's camera button — replace your profile photo.
 *
 * Stores the new file through the AvatarStorage port under
 * `avatars/{userId}/{uuid}-{name}`, points `users.avatar_path` at it, then
 * deletes the file the previous path pointed at so a re-upload never leaves an
 * orphan behind. Same "build the key in Infrastructure, hand the use case only
 * the result" split as Document's `DocumentUploadService`.
 *
 * Scoped to the acting user themselves — no policy, same as every other
 * ProfileController action.
 */
final class UpdateAvatar
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AvatarStorage $storage,
    ) {
    }

    public function handle(User $actor, UploadedFile $file): User
    {
        $previousPath = $actor->avatar_path;

        $path = sprintf(
            'avatars/%d/%s-%s',
            $actor->id,
            (string) Str::uuid(),
            $file->getClientOriginalName(),
        );

        $this->storage->put($path, (string) file_get_contents($file->getRealPath()));

        $user = $this->users->saveAttributes($actor, ['avatar_path' => $path]);

        // Only after the new path is committed — a failed write above must not
        // have removed the photo the user still has.
        if ($previousPath !== null && $previousPath !== $path) {
            $this->storage->delete($previousPath);
        }

        AuditLog::create([
            'provider_id' => $user->provider_id,
            'user_id' => $user->id,
            'action' => 'profile.avatar_updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
        ]);

        return $user;
    }
}
