<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding;

use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\ProviderLogoStorage;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The Portal Logo card's "Remove" button. Clears `providers.logo_path` (the
 * portal falls back to the PactTrack mark) and deletes the stored file. A
 * no-op audit-and-return when there was no logo to begin with.
 */
final class RemoveProviderLogo
{
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly ProviderLogoStorage $storage,
    ) {
    }

    public function handle(User $actor, Provider $provider): Provider
    {
        $previousPath = $provider->logo_path;

        if ($previousPath === null) {
            return $provider;
        }

        $provider->fill(['logo_path' => null]);
        $provider = $this->providers->save($provider);

        $this->storage->delete($previousPath);

        AuditLog::create([
            'provider_id' => $provider->id,
            'user_id' => $actor->id,
            'action' => 'branding.logo_removed',
            'auditable_type' => Provider::class,
            'auditable_id' => $provider->id,
        ]);

        return $provider;
    }
}
