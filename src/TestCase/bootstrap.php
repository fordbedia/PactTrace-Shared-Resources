<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the shared-resources package.
 *
 * Module code legitimately references the host application's `App\Models\User`
 * — Laravel owns the auth identity, and the User module extends it rather than
 * replacing it (see .claude/rules/user.md). Testbench boots a bare skeleton app
 * that has no such class, so the backend's namespaces are registered here.
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
        getenv('PACTTRACE_BACKEND_PATH') ?: null,
        dirname(__DIR__, 3) . '/backend',
        '/var/www/html',
    ]);

    foreach ($candidates as $candidate) {
        if (is_dir($candidate . '/app/Models')) {
            return rtrim($candidate, '/');
        }
    }

    throw new RuntimeException(
        'Could not locate the backend application. Looked in: ' . implode(', ', $candidates) . '. '
        . 'Set PACTTRACE_BACKEND_PATH to the Laravel app root.'
    );
})();

$loader = new ClassLoader();
$loader->addPsr4('App\\', $backendPath . '/app/');
$loader->addPsr4('Database\\Factories\\', $backendPath . '/database/factories/');
$loader->addPsr4('Database\\Seeders\\', $backendPath . '/database/seeders/');
$loader->register();
