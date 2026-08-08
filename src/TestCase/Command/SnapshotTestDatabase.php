<?php

namespace PactTraceSDK\SharedResources\TestCase\Command;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SnapshotTestDatabase extends Command
{
    protected $signature = 'testdb:snapshot
		{--db=app_db : Database name}
		{--user=root : DB username}
		{--host=mysql : DB host}
		{--port=3306 : DB port}
		{--file=src/TestCase/sqldumps/pacttrace.mysql.sql : Output dump path (relative to shared-resources root)}';

    protected $description = 'Create a MySQL SQL dump used by Testbench/RefreshDatabase for fast resets.';

    public function handle(): int
	{
		$root = dirname(__DIR__, 3); // .../shared-resources

		$outputRel = (string) $this->option('file');
		$output = $root . DIRECTORY_SEPARATOR . ltrim($outputRel, '/');
		$errFile = preg_replace('/\.sql$/', '.dump.err', $output) ?: ($output . '.dump.err');

		@mkdir(dirname($output), 0777, true);

		$db   = (string) $this->option('db');
		$user = (string) $this->option('user');

		$host = (string) $this->option('host');
		$port = (string) $this->option('port');
		$pass = env('TEST_DB_SNAPSHOT_PASSWORD', env('MYSQL_ROOT_PASSWORD', 'root'));

		if ($pass === '') {
			$this->error('Missing TEST_DB_SNAPSHOT_PASSWORD/MYSQL_ROOT_PASSWORD env var (needed for mysqldump).');
			return self::FAILURE;
		}

		$check = new Process(['sh', '-lc', 'command -v mysqldump'], $root);
		$check->run();
		if (! $check->isSuccessful()) {
			$this->error('mysqldump not found inside this container.');
			$this->line('Install it in your backend image: default-mysql-client (Debian/Ubuntu).');
			return self::FAILURE;
		}

		$this->info("Dumping DB '{$db}' from {$host}:{$port} as {$user}...");

		// Using env var avoids password appearing in process list
		$process = new Process([
			'mysqldump',
			'-h', $host,
			'-P', $port,
			'-u', $user,
			'--single-transaction',
			'--skip-lock-tables',
			'--routines',
			'--triggers',
			'--events',
			'--no-tablespaces',
			$db,
		], $root, [
			'MYSQL_PWD' => $pass,
		], null, 120);

		$process->run();
		$errorOutput = $process->getErrorOutput() ?: '';

		if (! $process->isSuccessful()) {
			file_put_contents($errFile, $errorOutput);
			$this->error("mysqldump failed. See: {$errFile}");
			$this->line($errorOutput);
			return self::FAILURE;
		}

		file_put_contents($output, $process->getOutput());
		if (file_exists($errFile)) {
			@unlink($errFile);
		}

		$size = @filesize($output) ?: 0;
		$this->info("✅ Dump written: {$output} (" . number_format($size) . " bytes)");

		return self::SUCCESS;
	}
}
