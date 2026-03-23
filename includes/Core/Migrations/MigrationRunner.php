<?php

declare(strict_types=1);

namespace BookingEngineConnector\Core\Migrations;

/**
 * Incremental DB migrations keyed by bec_db_version.
 */
final class MigrationRunner
{
	/**
	 * @var array<int, callable(): void>
	 */
	private static array $migrations = [];

	public static function register(int $version, callable $callback): void
	{
		self::$migrations[$version] = $callback;
	}

	public static function runPending(): void
	{
		$current = (int) get_option('bec_db_version', 0);

		ksort(self::$migrations, SORT_NUMERIC);

		foreach (self::$migrations as $version => $callback) {
			if ($version <= $current) {
				continue;
			}
			$callback();
			update_option('bec_db_version', (string) $version);
		}
	}
}
