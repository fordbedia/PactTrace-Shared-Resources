<?php

/**
 * Document module configuration.
 *
 * Merged automatically by SharedResourceServiceProvider::loadModules(), which
 * picks up every `config/*.php` in a module and merges it under the file's
 * name — so these live at `config('document.*')`. Merging means a
 * `config/document.php` published into the backend app overrides these key by
 * key.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Storage quota per plan (bytes)
    |--------------------------------------------------------------------------
    |
    | Backs the STORAGE indicator in the /dashboard/documents sidebar
    | ("6.2 GB of 10 GB used"). Keyed by `providers.plan`
    | (starter/professional/firm — see .claude/rules/user.md).
    |
    | These numbers are a DISPLAY figure only: nothing enforces them at upload
    | time, and DocumentController/UploadDocumentAction do not consult them.
    | Wiring an actual limit is a product decision (hard block? overage
    | billing? grace period?) that hasn't been made — when it is, this is the
    | value to enforce against, so the indicator and the limit can't drift.
    |
    | An unknown/missing plan falls back to `default`, which is deliberately
    | the smallest tier — a tenant whose plan we can't read should not be shown
    | a bigger allowance than they bought.
    |
    */

    'storage_quota_bytes' => [
        'starter' => 10 * 1024 * 1024 * 1024,        // 10 GB
        'professional' => 100 * 1024 * 1024 * 1024,  // 100 GB
        'firm' => 500 * 1024 * 1024 * 1024,          // 500 GB
        'default' => 10 * 1024 * 1024 * 1024,        // 10 GB
    ],

];
