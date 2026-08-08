<?php

namespace PactTraceSDK\SharedResources;

use PactTraceSDK\SharedResources\SDK\Application\Ports\Transactional;
use PactTraceSDK\SharedResources\SDK\Console\Config\CreateModule;
use PactTraceSDK\SharedResources\SDK\Console\Config\Make;
use PactTraceSDK\SharedResources\SDK\Console\Config\ResetTestData;
use PactTraceSDK\SharedResources\SDK\Infrastructure\Transactions\EloquentDBTransaction;
use PactTraceSDK\SharedResources\TestCase\Command\SnapshotTestDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use PactTraceSDK\SharedResources\Modules\User\UserProvider;
use PactTraceSDK\SharedResources\Modules\Client\ClientProvider;
use PactTraceSDK\SharedResources\Modules\Matter\MatterProvider;
use PactTraceSDK\SharedResources\Modules\Document\DocumentProvider;
use PactTraceSDK\SharedResources\Modules\Signature\SignatureProvider;
use PactTraceSDK\SharedResources\Modules\Messaging\MessagingProvider;
use PactTraceSDK\SharedResources\Modules\Notification\NotificationProvider;
use PactTraceSDK\SharedResources\Modules\Workspace\WorkspaceProvider;

class SharedResourceServiceProvider extends ServiceProvider
{
	/**
	 * Module service providers.
	 *
	 * Register each module's provider here as you scaffold it, e.g.
	 *   \PactTraceSDK\SharedResources\Modules\User\UserProvider::class,
	 */
    protected array $providers = [
		UserProvider::class,
		ClientProvider::class,
		MatterProvider::class,
		DocumentProvider::class,
		SignatureProvider::class,
		MessagingProvider::class,
		NotificationProvider::class,
		WorkspaceProvider::class,
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
						->namespace('PactTraceSDK\\SharedResources\\Modules\\'
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
