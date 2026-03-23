<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

/**
 * Prevents overlapping sync runs (transient lock).
 */
final class SyncLock
{
	private const KEY = 'bec_sync_running_lock';

	private const TTL = 300;

	public static function acquire(): bool
	{
		if (\get_transient(self::KEY) !== false) {
			return false;
		}

		\set_transient(self::KEY, '1', self::TTL);

		return true;
	}

	public static function release(): void
	{
		\delete_transient(self::KEY);
	}

	public static function isLocked(): bool
	{
		return \get_transient(self::KEY) !== false;
	}
}
