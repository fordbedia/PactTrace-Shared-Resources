<?php

namespace PactTrackSDK\SharedResources\Modules\Messaging;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Query\ProviderStaffDirectory;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Queries\EloquentProviderStaffDirectory;
use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Repositories\Eloquent\EloquentMessageRepository;
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

        // Persistence port -> Eloquent adapter, same hexagonal binding
        // shape as DocumentProvider (FolderRepository/DocumentRepository).
        $this->app->singleton(MessageRepository::class, EloquentMessageRepository::class);

        // Read model for the portal staff contact directory.
        $this->app->singleton(ProviderStaffDirectory::class, EloquentProviderStaffDirectory::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
