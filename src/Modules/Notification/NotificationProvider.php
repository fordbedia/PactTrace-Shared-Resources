<?php

namespace PactTrackSDK\SharedResources\Modules\Notification;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Infrastructure\Repositories\Eloquent\EloquentAuditLogRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Notification\Policies\AuditLogPolicy;

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

        $this->app->singleton(AuditLogRepository::class, EloquentAuditLogRepository::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
