<?php

namespace PactTrackSDK\SharedResources\Modules\Messaging;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Messaging\Policies\MessageThreadPolicy;

class MessagingProvider extends ServiceProvider
{
    protected array $providers = [
        //
    ];

    protected array $policies = [
        MessageThread::class => MessageThreadPolicy::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
