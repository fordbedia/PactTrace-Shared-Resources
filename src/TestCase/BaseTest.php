<?php

namespace PactTrackSDK\SharedResources\TestCase;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Fake\FakeSignatureProvider;
use PactTrackSDK\SharedResources\SharedResourceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\PermissionServiceProvider;

abstract class BaseTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            // Testbench boots a bare skeleton app and does not run Laravel's
            // package auto-discovery, so third-party providers the modules rely
            // on have to be listed here by hand.
            PermissionServiceProvider::class,
            SharedResourceServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Restore the snapshot dump only when the test opts in with
        // PactTrackSDK\SharedResources\TestCase\Extras\RefreshDatabase.
        if (method_exists($this, 'refreshDatabase')) {
            $this->refreshDatabase();
        }

        // Never let the test suite reach the real DocuSign API — see
        // .claude/rules/signature.md and the feature spec's NFR "Use the
        // FakeSignatureProvider in tests". Individual tests that need to
        // assert against a specific DocuSign HTTP call (e.g.
        // DocusignSignatureProviderTest) rebind this locally with
        // Http::fake() instead of relying on this default.
        $this->app->bind(ESignatureProvider::class, FakeSignatureProvider::class);
    }

	protected function getEnvironmentSetUp($app): void
	{
		$testingDatabase = env('PACTTRACK_MYSQL_TEST_DB_DATABASE', 'pacttrack_test');
		$applicationDatabase = env('DB_DATABASE');

		$this->assertTestingDatabaseIsSafe($testingDatabase, $applicationDatabase);

		// Testbench's skeleton app leaves the auth defaults empty. spatie derives
		// a model's guard from them, so without this every role assignment fails
		// with "should use guard `` instead of `web`" — the seeded catalogue is
		// on the `web` guard, as it is in the real app.
		$app['config']->set('auth.defaults.guard', 'web');
		$app['config']->set('auth.defaults.passwords', 'users');
		$app['config']->set('auth.guards.web', [
			'driver' => 'session',
			'provider' => 'users',
		]);
		$app['config']->set('auth.providers.users', [
			'driver' => 'eloquent',
			'model' => \PactTrackSDK\SharedResources\Modules\User\Models\User::class,
		]);

		$app['config']->set('database.default', 'testing');
		$app['config']->set('database.connections.testing', [
			'driver' => 'mysql',
			'host' => env('PACTTRACK_MYSQL_TEST_DB_HOST', 'mysql'),
			'port' => env('PACTTRACK_MYSQL_TEST_DB_PORT', '3306'),
			'database' => $testingDatabase,
			'username' => env('PACTTRACK_MYSQL_TEST_DB_USERNAME', 'pacttrack_u'),
			'password' => env('PACTTRACK_MYSQL_TEST_DB_PASSWORD', 'p4cttr4cekamikalara0213'),

			'charset' => 'utf8mb4',
			'collation' => 'utf8mb4_unicode_ci',
			'prefix' => '',
			'prefix_indexes' => true,
			'strict' => true,
			'engine' => 'InnoDB',
			'options' => extension_loaded('pdo_mysql') ? array_filter([
				// optional, prevents “server has gone away” for some dumps
				\PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES'",
			]) : [],
		]);
	}

	private function assertTestingDatabaseIsSafe(string $testingDatabase, ?string $applicationDatabase): void
	{
		if ($testingDatabase === '') {
			throw new \RuntimeException('PACTTRACK_MYSQL_TEST_DB_DATABASE must name a dedicated test database.');
		}

		if ($applicationDatabase !== null && $testingDatabase === $applicationDatabase) {
			throw new \RuntimeException(
				"Refusing to run tests against application database [{$testingDatabase}]. " .
				'Set PACTTRACK_MYSQL_TEST_DB_DATABASE to a dedicated test database.'
			);
		}

		if (!str_contains(strtolower($testingDatabase), 'test')) {
			throw new \RuntimeException(
				"Refusing to run tests against database [{$testingDatabase}] because its name does not contain [test]."
			);
		}
	}

}
