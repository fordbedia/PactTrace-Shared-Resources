<?php

/**
 * Workspace presets.
 *
 * Merged automatically by SharedResourceServiceProvider::loadModules(), which
 * picks up every `config/*.php` in a module and merges it under the file's
 * name — so these live at `config('workspace.*')`. Merging means a `config/
 * workspace.php` published into the backend app overrides these key by key.
 *
 * These are *starting points only*. Picking a type copies the two labels onto
 * the workspace row, and the row is what every screen reads from then on; a
 * provider is free to rename either one to anything afterwards, and editing
 * this file will not reach back and change workspaces that already exist.
 */

use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;

return [

    /*
    |--------------------------------------------------------------------------
    | Label presets per workspace type
    |--------------------------------------------------------------------------
    |
    | `client` is what the counterparty calls the provider, shown to the client
    | in the portal ("Your Attorney"). `engagement` is what a unit of work is
    | called, shown in both the portal and the dashboard ("Case", "Engagement").
    |
    | Every type in WorkspaceType should have an entry. A missing or blank one
    | falls back to `general` rather than erroring, so a bad edit here degrades
    | to plain wording instead of breaking the portal.
    |
    */

    'presets' => [

        WorkspaceType::Legal->value => [
            'client' => 'Your Attorney',
            'engagement' => 'Case',
        ],

        WorkspaceType::Accounting->value => [
            'client' => 'Your Accountant',
            'engagement' => 'Engagement',
        ],

        WorkspaceType::Consulting->value => [
            'client' => 'Your Consultant',
            'engagement' => 'Project',
        ],

        WorkspaceType::General->value => [
            'client' => 'Your Provider',
            'engagement' => 'Project',
        ],

    ],

];
