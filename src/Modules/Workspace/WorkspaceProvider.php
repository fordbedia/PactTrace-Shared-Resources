<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Workspace;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTraceSDK\SharedResources\Modules\Workspace\Application\Labels\WorkspaceLabelResolver;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\WorkspacePresets;
use PactTraceSDK\SharedResources\Modules\Workspace\Infrastructure\Context\RequestWorkspaceContext;
use PactTraceSDK\SharedResources\Modules\Workspace\Infrastructure\Presets\ConfigWorkspacePresets;
use PactTraceSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTraceSDK\SharedResources\Modules\Workspace\Policies\WorkspacePolicy;

class WorkspaceProvider extends ServiceProvider
{
    protected array $providers = [
        //
    ];

    protected array $policies = [
        Workspace::class => WorkspacePolicy::class,
    ];

    /**
     * Both ports are singletons for the same reason: the workspace context must
     * give one answer for the whole request, and the label resolver's cache is
     * worthless if a new instance is built per call.
     */
    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }

        require_once __DIR__.'/Support/helpers.php';

        $this->app->singleton(WorkspacePresets::class, function ($app): ConfigWorkspacePresets {
            return new ConfigWorkspacePresets($app['config']);
        });

        $this->app->singleton(CurrentWorkspace::class, function ($app): RequestWorkspaceContext {
            return new RequestWorkspaceContext($app['auth'], $app);
        });

        $this->app->singleton(WorkspaceLabelResolver::class, function ($app): WorkspaceLabelResolver {
            return new WorkspaceLabelResolver(
                $app->make(CurrentWorkspace::class),
                $app->make(WorkspacePresets::class),
            );
        });
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerBladeDirectives();
    }

    /**
     * `@workspaceLabel('client')` prints the current workspace's word for the
     * provider; `@workspaceLabel('engagement')` its word for a unit of work.
     *
     * Escaped on output like `{{ }}` rather than `{!! !!}` — these labels are
     * provider-supplied free text, so they are exactly the kind of string that
     * must not be trusted into HTML.
     */
    private function registerBladeDirectives(): void
    {
        Blade::directive('workspaceLabel', function (string $expression): string {
            return "<?php echo e(workspace_label({$expression})); ?>";
        });
    }
}
