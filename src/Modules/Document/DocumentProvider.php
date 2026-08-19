<?php

namespace PactTrackSDK\SharedResources\Modules\Document;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\FolderRepository;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Service\StorageUsageCalculator;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\StorageQuotas;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Quota\ConfigStorageQuotas;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent\EloquentDocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent\EloquentFolderRepository;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\S3\S3DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Services\DocumentStorageUsageService;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\Modules\Document\Policies\DocumentPolicy;
use PactTrackSDK\SharedResources\Modules\Document\Policies\FolderPolicy;

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
		$this->app->singleton(DocumentRepository::class, EloquentDocumentRepository::class);

		// STORAGE indicator on /dashboard/documents. The quota adapter reads
		// config/document.php; the calculator sums through the repository port
		// above, so it never sees a query builder.
		$this->app->singleton(StorageQuotas::class, fn ($app) => new ConfigStorageQuotas($app['config']));
		$this->app->singleton(StorageUsageCalculator::class, DocumentStorageUsageService::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
