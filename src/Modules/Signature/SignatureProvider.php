<?php

namespace PactTraceSDK\SharedResources\Modules\Signature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTraceSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTraceSDK\SharedResources\Modules\Signature\Infrastructure\Documenso\DocumensoClient;
use PactTraceSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTraceSDK\SharedResources\Modules\Signature\Policies\EnvelopePolicy;

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

        // Bind the e-signature port to the Documenso adapter. Swapping
        // providers (HelloSign/DocuSign) means adding a new adapter class
        // and changing only this binding — nothing in Domain/ or
        // Application/ references Documenso by name.
        $this->app->bind(ESignatureProvider::class, function () {
            $config = config('services.documenso', []);

            return new DocumensoClient(
                apiBaseUrl: $config['api_url'] ?? '',
                publicBaseUrl: $config['public_url'] ?? '',
                apiKey: $config['api_key'] ?? '',
            );
        });
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
