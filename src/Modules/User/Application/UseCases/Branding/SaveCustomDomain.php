<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding;

use Illuminate\Support\Str;
use InvalidArgumentException;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\CustomDomainAssignment;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomDomainName;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * `PUT /api/v1/branding/custom-domain` — the Custom Domain card's dedicated
 * save action (separate from the general `PATCH /branding`, which also still
 * accepts `custom_domain` for backward compatibility — see
 * UpdateProviderBranding — but doesn't reset the verification lifecycle the
 * way this does). The controller has already run gate #1
 * (`provider.manage-branding`) and gate #2 (`allowsCustomDomain`).
 *
 * A blank/null `$rawDomain` clears the domain entirely. See
 * Domain\Services\CustomDomainAssignment for the actual status-reset rules.
 */
final class SaveCustomDomain
{
    public function __construct(
        private readonly ProviderRepository $providers,
    ) {
    }

    public function handle(User $actor, Provider $provider, ?string $rawDomain): Provider
    {
        $normalized = $this->normalize($rawDomain);

        $attributes = CustomDomainAssignment::apply(
            currentDomain: $provider->custom_domain,
            newDomain: $normalized,
            freshToken: Str::random(40),
        );

        if ($attributes === []) {
            return $provider;
        }

        if ($attributes['custom_domain'] !== null
            && $this->providers->customDomainTakenByAnother($attributes['custom_domain'], (int) $provider->id)
        ) {
            throw new InvalidArgumentException("[{$attributes['custom_domain']}] is already in use by another account.");
        }

        $previousDomain = $provider->custom_domain;

        $provider->fill($attributes);
        $provider = $this->providers->save($provider);

        AuditLog::create([
            'provider_id' => $provider->id,
            'user_id' => $actor->id,
            'action' => 'branding.custom_domain_updated',
            'auditable_type' => Provider::class,
            'auditable_id' => $provider->id,
            'metadata' => [
                'previous_domain' => $previousDomain,
                'new_domain' => $provider->custom_domain,
            ],
        ]);

        return $provider;
    }

    /**
     * @throws InvalidArgumentException when a non-blank value isn't a
     *         syntactically valid hostname.
     */
    private function normalize(?string $rawDomain): string
    {
        $trimmed = trim((string) $rawDomain);

        if ($trimmed === '') {
            return '';
        }

        return CustomDomainName::fromString($trimmed)->value;
    }
}
