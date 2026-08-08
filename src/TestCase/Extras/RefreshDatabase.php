<?php

namespace PactTraceSDK\SharedResources\TestCase\Extras;

trait RefreshDatabase
{
	protected function refreshDatabase(): void
	{
		$root = dirname(__DIR__, 3);
		$dumpRelPath = env('PACTTRACE_MYSQL_TEST_DB_SNAPSHOT_FILE', 'src/TestCase/sqldumps/pacttrace.mysql.sql');
		$dumpPath = $root . '/' . ltrim($dumpRelPath, '/');

		if (!$dumpPath || !file_exists($dumpPath)) {
			throw new \RuntimeException(
				'MySQL dump not found at: ' . ($dumpPath ?: '[null]') .
				'. Generate it with `php artisan testdb:snapshot` from the backend container.'
			);
		}

		$connection = config('database.connections.testing');
		$db = (string) data_get($connection, 'database', '');
		$user = (string) data_get($connection, 'username', '');
		$pass = (string) data_get($connection, 'password', '');
		$host = (string) data_get($connection, 'host', '');
		$port = (string) data_get($connection, 'port', '3306');

		$this->assertSnapshotTargetIsSafe($db);

		$this->restoreMySqlDump($host, $port, $db, $user, $pass, $dumpPath);
	}

	private function assertSnapshotTargetIsSafe(string $database): void
	{
		if (!str_contains(strtolower($database), 'test')) {
			throw new \RuntimeException(
				"Refusing to restore a test snapshot into database [{$database}]."
			);
		}
	}

	private function restoreMySqlDump(
		string $host,
		string $port,
		string $database,
		string $username,
		string $password,
		string $dumpPathOnDisk
	): void {
		$cmd = [
			'sh', '-lc',
			sprintf(
				'MYSQL_PWD=%s mysql -h %s -P %s -u %s %s < %s',
				escapeshellarg($password),
				escapeshellarg($host),
				escapeshellarg($port),
				escapeshellarg($username),
				escapeshellarg($database),
				escapeshellarg($dumpPathOnDisk),
			),
		];

		$lastOutput = '';

		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$process = new \Symfony\Component\Process\Process($cmd, base_path('../../'));
			$process->setTimeout(180);
			$process->run();

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
