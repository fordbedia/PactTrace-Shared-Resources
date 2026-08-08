<?php

namespace PactTraceSDK\SharedResources\SDK\Console\Config;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PactTraceSDK\SharedResources\SDK\Console\Helpers\Traits\ResolveStubPath;

abstract class ModularMakeCommand extends ModularCommand
{
    use ResolveStubPath;

    protected string $what;
    protected string $className;
    protected ?string $module;
    protected ?string $table;
    protected ?string $create;
    protected ?string $modelName;
    protected string $fileName;
    protected string $stubPath;
    protected string $resource;
    protected string $factory;

    protected function initializeInputs(): void
    {
        $this->what = $this->argument('what');
        $this->className = $this->argument('className');
        $this->module = $this->option('module');
        $this->table = $this->option('table');
        $this->create = $this->option('create');
        $this->modelName = $this->option('model-name');
        $this->resource = $this->option('resource');
        $this->factory = $this->option('factory');
    }

    public function handle(): int
    {
        $this->initializeInputs();

        return match ($this->what) {
            'action'     => $this->makeAction(),
            'command'    => $this->makeCommand(),
            'controller' => $this->makeController(),
            'migration'  => $this->makeMigration(),
            'model'      => $this->makeModel(),
            'observer'   => $this->makeObserver(),
            'request'    => $this->makeRequest(),
            'resource'   => $this->makeResource(),
            'seeder'     => $this->makeSeeder(),
            'factory'    => $this->makeFactory(),
			'provider'    => $this->makeProvider(),
			'mail'    	=> $this->makeMail(),
			'job'		=> $this->makeJob(),
			'middleware' => $this->makeMiddleware(),
			'policy' 	=> $this->makePolicy(),
			'scope'		=> $this->makeScope(),
			'notification' => $this->makeNotification(),
            default      => $this->error('Invalid make option: ' . $this->what),
        };
    }

    protected function artisanCallOrCustom(string $artisanCommand, array $args = []): int
    {
        $this->prepareWriteContext($this->what);
        if ($this->module) {
            return $this->makeInModule($artisanCommand, $args);
        }

        return $this->call($artisanCommand, $args);
    }

    abstract protected function makeInModule(string $command, array $args): int;

    // You can implement default handlers and override as needed:
    protected function makeModel(): int
    {
        return $this->artisanCallOrCustom('make:model', [
            'name' => $this->className,
            '--migration' => $this->create !== null,
        ]);
    }

    protected function makeController(): int
    {
        return $this->artisanCallOrCustom('make:controller', [
            'name' => $this->className,
        ]);
    }

    protected function makeMigration(): int
    {
        return $this->artisanCallOrCustom('make:migration', [
            'name' => $this->className,
            '--create' => $this->create,
            '--table' => $this->table,
        ]);
    }

    protected function makeFactory(): int
    {
        return $this->artisanCallOrCustom('make:factory', [
            'name' => $this->className
        ]);
    }

    // Empty stubs — override these in child class as needed
    protected function makeAction(): int { return 0; }
    protected function makeCommand(): int {
		return $this->artisanCallOrCustom('make:command', [
			'name' => $this->className,
		]);
	}
    protected function makeObserver(): int {
		return $this->artisanCallOrCustom('make:observer', [
			'name' => $this->className,
			'--model-name' => $this->modelName,
		]);
	}
    protected function makeRequest(): int
	{
		return $this->artisanCallOrCustom('make:request', [
			'name' => $this->className
		]);
	}
    protected function makeResource(): int {
		return $this->artisanCallOrCustom('make:resource', [
			'name' => $this->className
		]);
	}

    protected function makeSeeder(): int
    {
        return $this->artisanCallOrCustom('make:seeder', [
            'name' => $this->className
        ]);
    }

	protected function makeProvider(): int
    {
        return $this->artisanCallOrCustom('make:provider', [
            'name' => $this->className
        ]);
    }

	public function makeMail(): int
	{
		return $this->artisanCallOrCustom('make:mail', [
            'name' => $this->className
        ]);
	}

	public function makeJob(): int
	{
		return $this->artisanCallOrCustom('make:job', [
            'name' => $this->className
        ]);
	}

	public function makeNotification(): int
	{
		return $this->artisanCallOrCustom('make:notification', [
            'name' => $this->className
        ]);
	}

	public function makeMiddleware(): int
	{
		return $this->artisanCallOrCustom('make:middleware', [
            'name' => $this->className
        ]);
	}

	public function makePolicy(): int
	{
		return $this->artisanCallOrCustom('make:policy', [
            'name' => $this->className
        ]);
	}

	public function makeScope(): int
	{
		return $this->artisanCallOrCustom('make:scope', [
            'name' => $this->className
        ]);
	}

    /**
     * @param $what
     */
    protected function prepareWriteContext($what): void
    {
        switch($what) {
            case 'migration':
                $this->fileName = date('Y_m_d_His').'_'.$this->className;
                if ($this->table) {
                    $this->stubPath = $this->resolveMigrationStubPath('update.stub');
                } else if ($this->create) {
                    $this->stubPath = $this->resolveMigrationStubPath('create.stub');
                } else {
                    $this->stubPath = $this->resolveMigrationStubPath('stub');
                }
                break;
            case 'controller':
                $this->fileName = $this->className;
                if ($this->resource) {
                    $this->stubPath = $this->resolveControllerStubPath('api.stub');
                } else {
                    $this->stubPath = $this->resolveControllerStubPath('plain.stub');
                }
                break;
            case 'model':
                $this->fileName = $this->className;
                if ($this->factory) {
                    $this->stubPath = $this->resolveModelStubPath('stub');
                } else {
                    $this->stubPath = $this->resolveModelStubPath('plain.stub');
                }
                break;
            case 'factory':
                $this->fileName = $this->className;
                $this->stubPath = $this->resolveFactoryStubPath('stub');
                break;
            case 'seeder':
                $this->fileName = $this->className;
                $this->stubPath = $this->resolveSeederStubPath('stub');
                break;
			case 'resource':
                $this->fileName = $this->className;
                $this->stubPath = $this->resolveResourceStubPath('stub');
                break;
			case 'provider':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolveProviderStubPath('stub');
				break;
			case 'request':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolveRequestStubPath('stub');
				break;
			case 'observer':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolveObserverStubPath('stub');
				break;
			case 'mail':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolveMailStubPath('stub');
				break;
			case 'job':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolveJobStubPath('stub');
				break;
			case 'middleware':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolveMiddlewareStubPath('stub');
				break;
			case 'policy':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolvePolicyStubPath('stub');
				break;
			case 'scope':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolveScopeStubPath('stub');
				break;
			case 'command':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolveCommandStubPath('stub');
				break;
			case 'notification':
				$this->fileName = $this->className;
				$this->stubPath = $this->resolveNotificationStubPath('stub');
				break;
        }
    }
}
