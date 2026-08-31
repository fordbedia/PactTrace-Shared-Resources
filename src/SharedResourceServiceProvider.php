<?php

namespace PactTrackSDK\SharedResources;

use PactTrackSDK\SharedResources\SDK\Application\Ports\Transactional;
use PactTrackSDK\SharedResources\SDK\Console\Config\CreateModule;
use PactTrackSDK\SharedResources\SDK\Console\Config\Make;
use PactTrackSDK\SharedResources\SDK\Console\Config\ResetTestData;
use PactTrackSDK\SharedResources\SDK\Infrastructure\Transactions\EloquentDBTransaction;
use PactTrackSDK\SharedResources\TestCase\Command\SnapshotTestDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use PactTrackSDK\SharedResources\Modules\User\UserProvider;
use PactTrackSDK\SharedResources\Modules\Client\ClientProvider;
use PactTrackSDK\SharedResources\Modules\Matter\MatterProvider;
use PactTrackSDK\SharedResources\Modules\Document\DocumentProvider;
use PactTrackSDK\SharedResources\Modules\Signature\SignatureProvider;
use PactTrackSDK\SharedResources\Modules\Messaging\MessagingProvider;
use PactTrackSDK\SharedResources\Modules\Notification\NotificationProvider;
use PactTrackSDK\SharedResources\Modules\Workspace\WorkspaceProvider;
use PactTrackSDK\SharedResources\Modules\Dashboard\DashboardProvider;

class SharedResourceServiceProvider extends ServiceProvider
{
	/**
	 * Module service providers.
	 *
	 * Register each module's provider here as you scaffold it, e.g.
	 *   \PactTrackSDK\SharedResources\Modules\User\UserProvider::class,
	 */
    protected array $providers = [
		UserProvider::class,
		ClientProvider::class,
		MatterProvider::class,
		DocumentProvider::class,
		SignatureProvider::class,
		MessagingProvider::class,
		NotificationProvider::class,
		WorkspaceProvider::class,		DashboardProvider::class,


    ];

    public function boot()
    {
        // Load module routes and migrations dynamically
        $this->loadModules();

        // Optional: Load SDK services, views, etc.
        // $this->loadViewsFrom(__DIR__ . '/SDK/resources/views', 'sdk');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateModule::class,
                Make::class,
                ResetTestData::class,
				SnapshotTestDatabase::class
            ]);
        }
    }

    protected function loadModules()
	{
		$modulesPath = __DIR__ . '/Modules';

		if (! is_dir($modulesPath)) {
			return;
		}

		$moduleDirs = File::directories($modulesPath);

		// In PHPUnit/Testbench we usually do not want routes auto-loaded
		$shouldLoadRoutes = ! $this->app->environment('testing');

		foreach ($moduleDirs as $moduleDir) {
			$moduleName = basename($moduleDir); // e.g., "Agreements"

			// ==============================================================
			// ROUTES (skip in testing)
			// ==============================================================
			if ($shouldLoadRoutes) {
				$web = $moduleDir . '/routes/web.php';
				if (file_exists($web)) {
					$this->loadRoutesFrom($web);
				}

				$api = $moduleDir . '/routes/api.php';
				if (file_exists($api)) {
					Route::prefix('api')
						->middleware('api')
						->namespace('PactTrackSDK\\SharedResources\\Modules\\'
							. $moduleName
							. '\\Http\\Controllers')
						->group($api);
				}
			}

			// ==============================================================
			// MIGRATIONS
			// ==============================================================
			$migrationPath = $moduleDir . '/Database/Migrations';
			if (is_dir($migrationPath)) {
				$this->loadMigrationsFrom($migrationPath);
			}

			// ==============================================================
			// VIEWS
			// ==============================================================
			$viewPath = $moduleDir . '/resources/views';
			if (is_dir($viewPath)) {
				$namespace = strtolower($moduleName);
				$this->loadViewsFrom($viewPath, $namespace);
			}

			// ==============================================================
			// CONFIG
			// ==============================================================
			$configPath = $moduleDir . '/config';
			if (is_dir($configPath)) {
				foreach (File::files($configPath) as $file) {
					$this->mergeConfigFrom(
						$file->getRealPath(),
						pathinfo($file->getFilename(), PATHINFO_FILENAME)
					);
				}
			}

			// ==============================================================
			// TRANSLATIONS
			// ==============================================================
			$langPath = $moduleDir . '/resources/lang';
			if (is_dir($langPath)) {
				$this->loadTranslationsFrom($langPath, strtolower($moduleName));
			}
		}
	}

    public function register()
    {
        // ==============================================================
        // Register all providers
        // ==============================================================
        foreach($this->providers as $provider) {
            $this->app->register($provider);
        }

		$this->app->singleton(Transactional::class, EloquentDBTransaction::class);
    }
}
