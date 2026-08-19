<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the shared-resources package.
 *
 * `App\Models\User` no longer exists — the User model lives in this package at
 * `Modules\User\Models\User` — but module code (e.g. controllers extending
 * `App\Http\Controllers\Controller`, `App\Http\Concerns\ResolvesActingUser`)
 * still legitimately references other classes the host `backend` app owns.
 * Testbench boots a bare skeleton app that has none of those, so the
 * backend's namespaces are registered here.
 *
 * This is done at bootstrap rather than as a composer `autoload-dev` path
 * because the two layouts disagree: on the host the backend sits beside this
 * package at `../backend`, while in the container it is mounted at
 * `/var/www/html`. A static path in composer.json can only ever satisfy one of
 * them; resolving it at runtime satisfies both.
 */

use Composer\Autoload\ClassLoader;

require __DIR__ . '/../../vendor/autoload.php';

$backendPath = (static function (): string {
    $candidates = array_filter([
        getenv('PACTTRACK_BACKEND_PATH') ?: null,
        dirname(__DIR__, 3) . '/backend',
        '/var/www/html',
    ]);

    foreach ($candidates as $candidate) {
        // `artisan` is present in every Laravel app root and, unlike
        // `app/Models`, doesn't depend on the backend still owning any
        // model classes (it no longer does — see the User model note above).
        if (is_file($candidate . '/artisan')) {
            return rtrim($candidate, '/');
        }
    }

    throw new RuntimeException(
        'Could not locate the backend application. Looked in: ' . implode(', ', $candidates) . '. '
        . 'Set PACTTRACK_BACKEND_PATH to the Laravel app root.'
    );
})();

$loader = new ClassLoader();
$loader->addPsr4('App\\', $backendPath . '/app/');
$loader->addPsr4('Database\\Factories\\', $backendPath . '/database/factories/');
$loader->addPsr4('Database\\Seeders\\', $backendPath . '/database/seeders/');
$loader->register();
