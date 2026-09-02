<?php

/**
 * Document module configuration.
 *
 * Merged automatically by SharedResourceServiceProvider::loadModules(), which
 * picks up every `config/*.php` in a module and merges it under the file's
 * name — so anything here lives at `config('document.*')`.
 *
 * `storage_quota_bytes` used to live here — a hand-maintained per-plan byte
 * array that had drifted from the plan list. Storage allowances are now derived
 * from `User\Domain\ValueObjects\Plan::storageLimitBytes()` (read by
 * `Infrastructure/Quota/PlanStorageQuotas`), so this file is intentionally
 * empty. Add real, environment-tunable settings here if the module ever needs
 * one.
 */

return [
    //
];
