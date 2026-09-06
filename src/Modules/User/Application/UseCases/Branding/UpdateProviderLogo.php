<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\ProviderLogoStorage;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The Portal Logo card's Replace / drop-zone upload on `/dashboard/branding`.
 *
 * A near-copy of Profile\UpdateAvatar: store the new file through the
 * ProviderLogoStorage port under `provider-logos/{providerId}/{uuid}-{name}`,
 * point `providers.logo_path` (+ `providers.disk`) at it, then delete the file
 * the previous path pointed at so a re-upload never orphans one. The new path
 * is committed before the old file is removed — a failed write must not have
 * destroyed the logo the tenant still has.
 */
final class UpdateProviderLogo
{
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly ProviderLogoStorage $storage,
    ) {
    }

    public function handle(User $actor, Provider $provider, UploadedFile $file): Provider
    {
        $previousPath = $provider->logo_path;

        $path = sprintf(
            'provider-logos/%d/%s-%s',
            $provider->id,
            (string) Str::uuid(),
            $file->getClientOriginalName(),
        );

        $this->storage->put($path, (string) file_get_contents($file->getRealPath()));

        $provider->fill(['logo_path' => $path, 'disk' => $this->storage->diskName()]);
        $provider = $this->providers->save($provider);

        if ($previousPath !== null && $previousPath !== $path) {
            $this->storage->delete($previousPath);
        }

        AuditLog::create([
            'provider_id' => $provider->id,
            'user_id' => $actor->id,
            'action' => 'branding.logo_updated',
            'auditable_type' => Provider::class,
            'auditable_id' => $provider->id,
        ]);

        return $provider;
    }
}
