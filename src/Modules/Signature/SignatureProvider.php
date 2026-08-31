<?php

namespace PactTrackSDK\SharedResources\Modules\Signature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Port\Repository\EnvelopeReadRepository;
use PactTrackSDK\SharedResources\Modules\Signature\Console\Commands\ReconcileStaleDocusignEnvelopes;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Repositories\Eloquent\EloquentEnvelopeReadRepository;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Docusign\DocusignSignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Docusign\JwtGrantAuthenticator;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Fake\FakeSignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Policies\EnvelopePolicy;
use RuntimeException;

class SignatureProvider extends ServiceProvider
{
    protected array $providers = [
        //
    ];

    protected array $policies = [
        Envelope::class => EnvelopePolicy::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }

        // Read-only aggregate access to `envelopes` for the /dashboard
        // "Signed This Month" card and "Signatures — Last 7 Days" chart.
        $this->app->singleton(EnvelopeReadRepository::class, EloquentEnvelopeReadRepository::class);

        // Bind the e-signature port to the DocuSign adapter (or the Fake,
        // via SIGNATURE_PROVIDER=fake — see config/services.php). Swapping
        // providers means adding a new adapter class and changing only this
        // binding — nothing in Domain/ or Application/ references DocuSign
        // by name.
        //
        // singleton() (not bind()) is deliberate: DocusignSignatureProvider
        // memoizes its JWT Grant session for its own lifetime, and a JWT
        // Grant access token is valid for 10 minutes — sharing one instance
        // for the life of a request (the normal PHP-FPM/artisan-serve
        // lifecycle) is exactly the "cache only within a request lifecycle"
        // behavior the feature calls for, with no separate cache store.
        $this->app->singleton(ESignatureProvider::class, function () {
            $config = config('services.docusign', []);

            if (($config['driver'] ?? 'docusign') === 'fake') {
                return new FakeSignatureProvider();
            }

            $authenticator = new JwtGrantAuthenticator(
                clientId: $config['client_id'] ?? '',
                impersonatedUserGuid: $config['impersonated_user_guid'] ?? '',
                accountId: $config['account_id'] ?? '',
                authServer: $config['auth_server'] ?? 'account-d.docusign.com',
                privateKeyPem: $this->resolvePrivateKey($config['private_key_path'] ?? null),
                // Must be registered as a Redirect URI on the DocuSign app itself
                // (Settings > Apps and Keys) — DocuSign rejects any redirect_uri
                // on the one-time consent grant that isn't pre-registered there,
                // regardless of what domain it points to.
                consentRedirectUri: rtrim((string) config('app.frontend_url'), '/') . '/docusign-return',
            );

            return new DocusignSignatureProvider(
                auth: $authenticator,
                webhookHmacKey: $config['connect_hmac_key'] ?? '',
            );
        });
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileStaleDocusignEnvelopes::class,
            ]);
        }
    }

    private function resolvePrivateKey(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        $fullPath = str_starts_with($path, '/') ? $path : storage_path($path);

        if (! is_readable($fullPath)) {
            throw new RuntimeException(
                "DocuSign private key not found or not readable at [{$fullPath}] — check "
                . 'DOCUSIGN_PRIVATE_KEY_PATH.'
            );
        }

        return (string) file_get_contents($fullPath);
    }
}
