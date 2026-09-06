<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding;

use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * "Save Changes" on `/dashboard/branding` — the non-file fields (accent
 * colour, portal name/subdomain/timezone/locale/custom domain, and the Email
 * Branding block). One partial-update path; the controller has already run the
 * permission + plan gates and dropped any field the caller isn't allowed to
 * set, so this use case trusts its input shape and only normalises/persists.
 *
 * Client / engagement terminology is NOT handled here — it lives on
 * `workspaces` (client_label / engagement_label) and the screen writes it
 * through the Workspace module's existing `PUT /workspaces/{id}`.
 *
 * A `subdomain` value is run through the {@see Subdomain} value object so the
 * DNS-grammar and reserved-word rules apply here too, not just at sign-up — an
 * `\InvalidArgumentException` surfaces to the controller as a 422.
 */
final class UpdateProviderBranding
{
    /** The only keys this use case will write. */
    private const WRITABLE = [
        'business_name',
        'subdomain',
        'custom_domain',
        'primary_color',
        'timezone',
        'locale',
        'email_sender_name',
        'email_reply_to',
        'email_powered_by_footer',
    ];

    public function __construct(
        private readonly ProviderRepository $providers,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function handle(User $actor, Provider $provider, array $attributes): Provider
    {
        $attributes = array_intersect_key($attributes, array_flip(self::WRITABLE));

        if (array_key_exists('subdomain', $attributes) && $attributes['subdomain'] !== null) {
            $attributes['subdomain'] = Subdomain::fromString((string) $attributes['subdomain'])->value;
        }

        if (array_key_exists('custom_domain', $attributes)) {
            $domain = is_string($attributes['custom_domain']) ? trim($attributes['custom_domain']) : null;
            $attributes['custom_domain'] = $domain !== null && $domain !== '' ? strtolower($domain) : null;
        }

        $changed = [];
        foreach ($attributes as $key => $value) {
            if ((string) $provider->getAttribute($key) !== (string) $value) {
                $changed[] = $key;
            }
        }

        if ($changed === []) {
            return $provider;
        }

        $provider->fill($attributes);
        $provider = $this->providers->save($provider);

        AuditLog::create([
            'provider_id' => $provider->id,
            'user_id' => $actor->id,
            'action' => 'branding.updated',
            'auditable_type' => Provider::class,
            'auditable_id' => $provider->id,
            'metadata' => ['changed' => $changed],
        ]);

        return $provider;
    }
}
