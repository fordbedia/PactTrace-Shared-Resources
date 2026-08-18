<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\TestCase\Extras;

use Illuminate\Routing\Middleware\SubstituteBindings;

/**
 * Registers a module's own `routes/api.php` for an HTTP-level test.
 *
 * SharedResourceServiceProvider::loadModules() deliberately skips route
 * loading under the `testing` environment, so a controller test would
 * otherwise 404 on every request. Rather than flipping that off globally
 * (which would load *every* module's routes into *every* test), a controller
 * test opts in to the one module it exercises:
 *
 *     use LoadsModuleApiRoutes;
 *
 *     protected function moduleApiRoutes(): array
 *     {
 *         return [__DIR__ . '/../routes/api.php'];
 *     }
 *
 * The routes are mounted under the same `/api` prefix the provider uses, so
 * the paths in a test read exactly as they do in the browser. `SubstituteBindings`
 * is applied explicitly because implicit route-model binding (`{folder}` ->
 * Folder) is middleware, not routing — without it a bound parameter arrives as
 * a raw string and the controller blows up on a type error.
 */
trait LoadsModuleApiRoutes
{
    /**
     * Absolute paths to the route files to mount.
     *
     * @return array<int, string>
     */
    abstract protected function moduleApiRoutes(): array;

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        foreach ($this->moduleApiRoutes() as $routeFile) {
            $router->prefix('api')
                ->middleware([SubstituteBindings::class])
                ->group($routeFile);
        }
    }
}
