<?php

namespace PactTraceSDK\SharedResources\Modules\Notification;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTraceSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTraceSDK\SharedResources\Modules\Notification\Policies\AuditLogPolicy;

class NotificationProvider extends ServiceProvider
{
    protected array $providers = [
        //
    ];

    protected array $policies = [
        AuditLog::class => AuditLogPolicy::class,
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
