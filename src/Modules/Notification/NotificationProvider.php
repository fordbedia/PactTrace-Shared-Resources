<?php

namespace PactTrackSDK\SharedResources\Modules\Notification;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationPreferenceRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Preferences\NotificationPreferenceResolver;
use PactTrackSDK\SharedResources\Modules\Notification\Infrastructure\Repositories\Eloquent\EloquentAuditLogRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Infrastructure\Repositories\Eloquent\EloquentNotificationPreferenceRepository;
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

        // `notification_enabled()` global. Required here (not autoload.files) so
        // adding it doesn't force an autoloader re-dump — same as the Workspace
        // module's helpers.
        require_once __DIR__.'/Support/helpers.php';

        $this->app->singleton(AuditLogRepository::class, EloquentAuditLogRepository::class);

        $this->app->bind(
            NotificationPreferenceRepository::class,
            EloquentNotificationPreferenceRepository::class,
        );

        // Singleton so the catalogue is read once per request and repeated
        // `Notification::isset(...)` calls are free — mirrors WorkspaceLabelResolver.
        $this->app->singleton(NotificationPreferenceResolver::class, function ($app): NotificationPreferenceResolver {
            return new NotificationPreferenceResolver(
                $app->make(NotificationPreferenceRepository::class),
                $app['auth'],
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
