<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\TestCase\Extras;

/**
 * Process-wide "has the test DB snapshot been restored yet" flag for
 * RefreshDatabase::restoreSnapshotOnce().
 *
 * Kept in its own class rather than as a static on the trait: a static
 * declared in a trait is copied into every class that uses it (each test
 * class would get its own flag), which would silently turn "once per process"
 * back into "once per test class".
 */
final class SnapshotState
{
	public static bool $restored = false;

	/**
	 * Set when a restore fails, so every later test in the same process
	 * fails immediately with the same error instead of re-running the
	 * (up to 3 x 60s) restore again.
	 */
	public static ?string $failure = null;
}
