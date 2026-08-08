<?php

declare(strict_types=1);

use PactTraceSDK\SharedResources\Modules\Workspace\Application\Labels\WorkspaceLabelResolver;

/**
 * Global helpers for the Workspace module.
 *
 * Required from WorkspaceProvider::register() rather than declared in
 * composer's `autoload.files`, so adding them does not oblige every consumer to
 * re-dump the autoloader of both this package and the backend app.
 */
if (! function_exists('workspace_label')) {
    /**
     * The current workspace's word for something.
     *
     *     workspace_label('client')      // "Your Attorney"
     *     workspace_label('engagement')  // "Case"
     *
     * Never returns an empty string. With no workspace context, or a workspace
     * whose labels are blank, it falls back to the general preset — so a screen
     * calling this always has a noun to print, and copy never collapses to
     * "Your " mid-sentence.
     *
     * @param  'client'|'engagement'  $key
     */
    function workspace_label(string $key): string
    {
        return app(WorkspaceLabelResolver::class)->label($key);
    }
}

if (! function_exists('workspace_labels')) {
    /**
     * Both labels at once, for a view that needs each of them.
     *
     * @return array{client: string, engagement: string}
     */
    function workspace_labels(): array
    {
        return app(WorkspaceLabelResolver::class)->labels()->toArray();
    }
}
