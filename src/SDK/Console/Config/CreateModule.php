<?php

namespace PactTrackSDK\SharedResources\SDK\Console\Config;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CreateModule extends Command
{
    protected $signature = 'pacttrack:createmodule {moduleName : Module name, e.g. AdvancedFilter}';

    protected $description = 'Create a shared-resources module scaffold';

    public function handle(): int
    {
        $moduleName = $this->normalizeModuleName((string) $this->argument('moduleName'));

        if ($moduleName === null) {
            $this->error('The module name must start with a letter and only contain letters, numbers, underscores, or hyphens.');

            return self::FAILURE;
        }

        $sourcePath = dirname(__DIR__, 3);
        $modulePath = "{$sourcePath}/Modules/{$moduleName}";
        $providerClass = "{$moduleName}Provider";
        $providerPath = "{$modulePath}/{$providerClass}.php";
        $serviceProviderPath = "{$sourcePath}/SharedResourceServiceProvider.php";

        if (! File::exists($serviceProviderPath)) {
            $this->error("SharedResourceServiceProvider.php was not found at {$serviceProviderPath}.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($modulePath);

        $this->ensureDirectories($modulePath);
        $this->ensureRoutes($modulePath);
        $this->ensureModuleProvider($moduleName, $providerClass, $providerPath);

        if (! $this->registerModuleProvider($moduleName, $providerClass, $serviceProviderPath)) {
            return self::FAILURE;
        }

        $this->info("Module {$moduleName} is ready.");

        return self::SUCCESS;
    }

    protected function normalizeModuleName(string $moduleName): ?string
    {
        $moduleName = trim($moduleName);

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $moduleName)) {
            return null;
        }

        return Str::studly($moduleName);
    }

    protected function ensureDirectories(string $modulePath): void
    {
        foreach ([
            "{$modulePath}/resources/views",
            "{$modulePath}/routes",
            "{$modulePath}/Tests",
        ] as $directory) {
            File::ensureDirectoryExists($directory);
        }
    }

    protected function ensureRoutes(string $modulePath): void
    {
        foreach ([
            "{$modulePath}/routes/api.php",
            "{$modulePath}/routes/web.php",
        ] as $routeFile) {
            if (! File::exists($routeFile)) {
                File::put($routeFile, "<?php\n\n");
            }
        }
    }

    protected function ensureModuleProvider(string $moduleName, string $providerClass, string $providerPath): void
    {
        if (File::exists($providerPath)) {
            $this->line("Provider already exists: {$providerPath}");

            return;
        }

        File::put($providerPath, <<<PHP
<?php

namespace PactTrackSDK\\SharedResources\\Modules\\{$moduleName};

use Illuminate\\Support\\ServiceProvider;

class {$providerClass} extends ServiceProvider
{
    protected array \$providers = [
        //
    ];

    public function register(): void
    {
        foreach (\$this->providers as \$provider) {
            \$this->app->register(\$provider);
        }
    }

    public function boot(): void
    {
        //
    }
}

PHP);
    }

    protected function registerModuleProvider(string $moduleName, string $providerClass, string $serviceProviderPath): bool
    {
        $contents = File::get($serviceProviderPath);
        $providerFqn = "PactTrackSDK\\SharedResources\\Modules\\{$moduleName}\\{$providerClass}";
        $useStatement = "use {$providerFqn};";
        $providerEntry = "{$providerClass}::class,";
        $updated = $contents;

        if (! str_contains($updated, $useStatement)) {
            $updated = $this->insertUseStatement($updated, $useStatement);
        }

        if (! str_contains($updated, $providerEntry)) {
            $updated = $this->insertProviderEntry($updated, $providerEntry);
        }

        if ($updated === null) {
            $this->error('Unable to update the $providers property in SharedResourceServiceProvider.php.');

            return false;
        }

        if ($updated !== $contents) {
            File::put($serviceProviderPath, $updated);
            $this->line("Registered {$providerClass} in SharedResourceServiceProvider.php.");
        } else {
            $this->line("{$providerClass} is already registered in SharedResourceServiceProvider.php.");
        }

        return true;
    }

    protected function insertUseStatement(string $contents, string $useStatement): string
    {
        if (preg_match_all('/^use .+;$/m', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $lastUse = end($matches[0]);
            $insertAt = $lastUse[1] + strlen($lastUse[0]);

            return substr($contents, 0, $insertAt)
                . "\n{$useStatement}"
                . substr($contents, $insertAt);
        }

        return preg_replace(
            '/^(namespace PactTrackSDK\\\\SharedResources;\R)/m',
            "$1\n{$useStatement}\n",
            $contents,
            1
        ) ?? $contents;
    }

    protected function insertProviderEntry(string $contents, string $providerEntry): ?string
    {
        $result = preg_replace(
            '/(protected\s+array\s+\$providers\s*=\s*\[\R)(.*?)(\s*\];)/s',
            "$1$2\t\t{$providerEntry}\n$3",
            $contents,
            1,
            $count
        );

        if ($result === null || $count === 0) {
            return null;
        }

        return $result;
    }
}
