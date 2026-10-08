<?php

namespace PactTrackSDK\SharedResources\TestCase\Extras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PactTrackSDK\SharedResources\TestCase\BaseTest;
use Symfony\Component\Process\Process;

/**
 * Gives every test a clean copy of the test database, cheaply:
 *
 * 1. restoreSnapshotOnce() pipes the snapshot dump into MySQL ONCE per PHP
 *    process (called from BaseTest::setUp() before Testbench boots — see the
 *    comment there for why the order matters).
 * 2. DatabaseTransactions makes Testbench begin a transaction on the
 *    `testing` connection inside parent::setUp() and roll it back (then
 *    disconnect) when the test's application is torn down. Nothing a test
 *    writes is ever committed, so the DB is back at the snapshot after every
 *    test and after the whole run.
 *
 * Previously the dump was restored before EVERY test (~1,300 full DDL
 * rebuilds per run). Set PACTTRACK_TEST_RESTORE_EACH_TEST=1 in the shell to
 * get that behaviour back when chasing a suspected state leak (don't put it
 * in phpunit.xml — its <env force="true"> entries would override the shell).
 *
 * Under a transaction a test must not: run DDL (MySQL commits implicitly),
 * read through a second DB connection (it can't see uncommitted rows), or
 * hard-code auto-increment ids (rollback doesn't reset the counters).
 */
trait RefreshDatabase
{
	use DatabaseTransactions;

	/**
	 * A property, not a method — a connectionsToTransact() method would
	 * collide with DatabaseTransactions::connectionsToTransact(), which reads
	 * this property when it exists.
	 *
	 * @var array<int, string>
	 */
	protected array $connectionsToTransact = ['testing'];

	public static function restoreSnapshotOnce(): void
	{
		if (SnapshotState::$failure !== null) {
			throw new \RuntimeException(
				"Skipped: the test DB snapshot restore already failed earlier in this run.\n" . SnapshotState::$failure
			);
		}

		if (SnapshotState::$restored && ! self::restoreEachTest()) {
			return;
		}

		$root = dirname(__DIR__, 3);
		$dumpRelPath = env('PACTTRACK_MYSQL_TEST_DB_SNAPSHOT_FILE', 'src/TestCase/sqldumps/pacttrack.mysql.sql');
		$dumpPath = $root . '/' . ltrim($dumpRelPath, '/');

		if (!$dumpPath || !file_exists($dumpPath)) {
			throw new \RuntimeException(
				'MySQL dump not found at: ' . ($dumpPath ?: '[null]') .
				'. Generate it with `php artisan testdb:snapshot` from the backend container.'
			);
		}

		$connection = BaseTest::connectionConfigForTesting();
		$db = (string) ($connection['database'] ?? '');
		$user = (string) ($connection['username'] ?? '');
		$pass = (string) ($connection['password'] ?? '');
		$host = (string) ($connection['host'] ?? '');
		$port = (string) ($connection['port'] ?? '3306');

		BaseTest::assertTestingDatabaseIsSafe($db, env('DB_DATABASE'));
		self::assertSnapshotTargetIsSafe($db);

		try {
			self::restoreMySqlDump($host, $port, $db, $user, $pass, $dumpPath, $root);
		} catch (\RuntimeException $e) {
			SnapshotState::$failure = $e->getMessage();

			throw $e;
		}

		SnapshotState::$restored = true;
	}

	private static function restoreEachTest(): bool
	{
		return filter_var(getenv('PACTTRACK_TEST_RESTORE_EACH_TEST') ?: '0', FILTER_VALIDATE_BOOLEAN);
	}

	private static function assertSnapshotTargetIsSafe(string $database): void
	{
		if (!str_contains(strtolower($database), 'test')) {
			throw new \RuntimeException(
				"Refusing to restore a test snapshot into database [{$database}]."
			);
		}
	}

	private static function restoreMySqlDump(
		string $host,
		string $port,
		string $database,
		string $username,
		string $password,
		string $dumpPathOnDisk,
		string $workingDirectory
	): void {
		$cmd = [
			'sh', '-lc',
			sprintf(
				// --connect-timeout + lock_wait_timeout: fail fast instead of
				// hanging when MySQL is unreachable, or when a DROP TABLE
				// waits on a metadata lock held by a stray connection (the
				// server default lock_wait_timeout is a full year).
				'MYSQL_PWD=%s mysql --connect-timeout=5 --init-command=%s -h %s -P %s -u %s %s < %s',
				escapeshellarg($password),
				escapeshellarg('SET SESSION lock_wait_timeout=30'),
				escapeshellarg($host),
				escapeshellarg($port),
				escapeshellarg($username),
				escapeshellarg($database),
				escapeshellarg($dumpPathOnDisk),
			),
		];

		$lastOutput = '';

		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$process = new Process($cmd, $workingDirectory);
			$process->setTimeout(60);

			try {
				$process->run();
			} catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
				$lastOutput = $e->getMessage();

				if ($attempt < 3) {
					usleep(250000 * $attempt);
				}

				continue;
			}

			if ($process->isSuccessful()) {
				return;
			}

			$lastOutput = $process->getErrorOutput() . $process->getOutput();

			if ($attempt < 3) {
				usleep(250000 * $attempt);
			}
		}

		throw new \RuntimeException("mysql restore failed:\n" . $lastOutput);
	}
}
