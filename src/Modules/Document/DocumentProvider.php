<?php

namespace PactTraceSDK\SharedResources\Modules\Document;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository\FolderRepository;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent\EloquentFolderRepository;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\S3\S3DocumentStorage;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Document\Models\Folder;
use PactTraceSDK\SharedResources\Modules\Document\Policies\DocumentPolicy;
use PactTraceSDK\SharedResources\Modules\Document\Policies\FolderPolicy;

class DocumentProvider extends ServiceProvider
{
    protected array $providers = [
        //
    ];

    protected array $policies = [
        Document::class => DocumentPolicy::class,
        Folder::class => FolderPolicy::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }

        // Bound to the `s3` disk name, but S3DocumentStorage just wraps
        // whatever Laravel disk it's given — point DOCUMENT_STORAGE_DISK at
        // `local` for dev environments with no AWS credentials configured.
        $this->app->bind(DocumentStorage::class, fn () => new S3DocumentStorage(
            disk: config('filesystems.document_disk', 's3'),
        ));

		$this->app->singleton(FolderRepository::class, EloquentFolderRepository::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
