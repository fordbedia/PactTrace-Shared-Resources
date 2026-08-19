<?php

namespace PactTrackSDK\SharedResources\SDK\Console\Config;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class Make extends ModularMakeCommand
{
    protected $signature = 'pacttrack:make {what} {className}
        {--module= : Module Name}
        {--table= : Name of table to derive model or migration from}
        {--create= : Create a migration file for the model}
        {--model-name= : Model to be targeted for observer}
        {--resource : Turn controller into a formatted API HTTP request}
        {--factory : Factory of the model}';

    protected $description = 'Create Laravel components in models or the default app paths';

    /**
     * @throws \Exception
     */
    protected function makeInModule(string $command, array $args): int
    {
        // Define the module root directory
        $modulePath = base_path("vendor/pacttrack/shared-resources/src/Modules/{$this->module}");

        // Define subpaths based on component type
        $paths = [
            'make:model'      	=> 'Models',
            'make:controller'	=> 'Http/Controllers',
            'make:migration'  	=> 'Database/Migrations',
            'make:seeder'     	=> 'Database/Seeders',
            'make:factory'    	=> 'Database/Factories',
			'make:resource'   	=> 'Http/Resources',
			'make:provider'  	=> 'Providers',
			'make:request'    	=> 'Http/Requests',
			'make:observer'   	=> 'Observers',
			'make:mail'			=> 'Mail',
			'make:job'			=> 'Jobs',
			'make:middleware'	=> 'Http/Middleware',
			'make:policy'		=> 'Policies',
			'make:scope'		=> 'Scopes',
			'make:command'		=> 'Console/Commands',
			'make:notification' => 'Notifications'
        ];

        $subPath = $paths[$command] ?? 'Misc';
        $fullPath = "{$modulePath}/{$subPath}";

        // Ensure directory exists
        File::ensureDirectoryExists($fullPath);

        // Show in progress
        $this->inProgressInfo();

        // Generate the file manually or call Artisan with a custom path
        $filename =$this->fileName . '.php';
        $targetPath = "{$fullPath}/{$filename}";
        $rawStub = file_get_contents($this->stubPath);
        $compiledStubPath = $this->populateStub($rawStub);

        // For now, just a file stub — you can customize with real stubs later
        if (! file_exists($targetPath)) {
            File::put($targetPath, $compiledStubPath);
            $this->completed("Created <success>{$this->what}</success> in module <success>[{$this->module}]</success>: {$targetPath}");
        } else {
            $this->completed("File already exist at {$this->module}: {$targetPath}");
        }

        return 0;
    }

    /**
     * @param string $stub
     * @return string
     */
    protected function populateStub(string $stub): string
    {
        return str_replace(
            [
                '{{ namespace }}',
                '{{ class }}',
                '{{ rootNamespace }}',
                '{{ table }}',
                '{{ factoryImport }}',
                '{{ factory }}',
				'{{ model }}',
				'{{ modelVariable }}',
            ],
            [
                $this->calculateNamespace(),
                $this->className,
                app()->getNamespace(),
                $this->table ?: $this->create, // fallback if --table is not provided
                $this->factory ? 'use Illuminate\Database\Eloquent\Factories\HasFactory;' : '',
                $this->factory ? 'use HasFactory;' : '',
				$this->modelName,
				Str::camel($this->modelName)
            ],
            $stub
        );
    }

    /**
     * @return string
     */
    protected function calculateNamespace(): string
    {
        switch($this->what) {
            case 'controller':
                // Example: Vendor\Package\Modules\User\Http\Controllers
                return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Http\\Controllers';
                break;
            case 'model':
                return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Models';
            case 'factory':
                return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Database\\Factories';
            case 'seeder':
                 return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Database\\Seeders';
			case 'resource':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Http\\Resources';
			case 'provider':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Providers';
			case 'request':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Http\\Requests';
			case 'observer':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Observers';
			case 'mail':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Mail';
			case 'job':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Jobs';
			case 'middleware':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Http\\Middleware';
			case 'policy':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Policies';
			case 'scope':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Scopes';
			case 'command':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Console\\Commands';
			case 'notification':
				return 'PactTrackSDK\\SharedResources\\Modules\\' . $this->module . '\\Notifications';
            default:
                return '';
        }
    }

    protected function commandType():string { return 'make'; }
}
