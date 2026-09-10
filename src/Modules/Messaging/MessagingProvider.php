<?php

namespace PactTrackSDK\SharedResources\Modules\Messaging;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Repositories\Eloquent\EloquentMessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Storage\MessageAttachmentStorageSource;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Messaging\Policies\MessageThreadPolicy;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StorageSource;

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

        // `message_attachments` bytes now count toward the provider's plan
        // storage — the gap this closes. Collected with every other module's
        // StorageSource by User\Application\Services\StorageUsageAggregator.
        $this->app->tag(MessageAttachmentStorageSource::class, StorageSource::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
