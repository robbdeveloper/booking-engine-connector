<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

/**
 * Prevents overlapping sync runs; supports cron vs manual run ownership and TTL refresh.
 */
final class SyncLock
{
	private const KEY = 'bec_sync_running_lock';

	private const TTL_SECONDS = 7200;

	private const CRON_VALUE = 'c';

	/**
	 * @return non-empty-string
	 */
	private static function manualValue(int $userId, string $sanitizedRunId): string
	{
		return 'm:' . $userId . ':' . $sanitizedRunId;
	}

	/**
	 * Acquire for WP-Cron / admin-post full sync (no AJAX run id). Fails if a manual run holds the lock.
	 */
	public static function acquireCron(): bool
	{
		$cur = \get_transient(self::KEY);
		if ($cur === false) {
			\set_transient(self::KEY, self::CRON_VALUE, self::TTL_SECONDS);

			return true;
		}

		if ($cur === self::CRON_VALUE) {
			self::refresh();

			return true;
		}

		return false;
	}

	/**
	 * Manual AJAX batch sync: acquire or re-enter the same run (step loop).
	 *
	 * If the lock is held by another run id for the **same user**, it is cleared when that run’s
	 * batch state is gone (orphan lock) or {@see bec_sync_manual_lock_abandon_seconds} has elapsed
	 * since the last state update (e.g. user refreshed and got a new run UUID).
	 *
	 * @param non-empty-string $sanitizedRunId From {@see SyncProgressReporter::sanitizeRunId()}.
	 */
	public static function acquireManual(int $userId, string $sanitizedRunId): bool
	{
		$desired = self::manualValue($userId, $sanitizedRunId);
		$cur     = \get_transient(self::KEY);

		if ($cur === false) {
			\set_transient(self::KEY, $desired, self::TTL_SECONDS);

			return true;
		}

		if ($cur === $desired) {
			self::refresh();

			return true;
		}

		if (\is_string($cur) && self::tryReleaseStaleManualLockForSameUser($userId, $sanitizedRunId, $cur)) {
			\set_transient(self::KEY, $desired, self::TTL_SECONDS);

			return true;
		}

		if (
			$cur === self::CRON_VALUE
			&& (bool) \apply_filters('bec_sync_manual_may_preempt_cron_lock', false, $userId, $sanitizedRunId)
		) {
			\delete_transient(self::KEY);
			\set_transient(self::KEY, $desired, self::TTL_SECONDS);

			return true;
		}

		return false;
	}

	/**
	 * @param non-empty-string $sanitizedNewRunId
	 */
	private static function tryReleaseStaleManualLockForSameUser(int $userId, string $sanitizedNewRunId, string $cur): bool
	{
		$parsed = self::parseManualLockHolder($cur);
		if ($parsed === null || $parsed['user_id'] !== $userId) {
			return false;
		}

		if ($parsed['run_id'] === $sanitizedNewRunId) {
			return false;
		}

		$oldRun = $parsed['run_id'];
		$state  = SyncManualBatchState::get($userId, $oldRun);

		if ($state === null) {
			\delete_transient(self::KEY);

			return true;
		}

		$minute       = \defined('MINUTE_IN_SECONDS') ? \constant('MINUTE_IN_SECONDS') : 60;
		$defaultIdle  = 30 * (int) $minute;
		/** @var int $abandonAfter */
		$abandonAfter = (int) \apply_filters('bec_sync_manual_lock_abandon_seconds', $defaultIdle, $userId, $oldRun);
		if ($abandonAfter < 1) {
			return false;
		}

		$updated = (int) ( $state['updated_at'] ?? 0 );
		if ($updated > 0 && ( \time() - $updated ) > $abandonAfter) {
			SyncManualBatchState::delete($userId, $oldRun);
			\delete_transient(self::KEY);

			return true;
		}

		return false;
	}

	/**
	 * @return array{user_id: int, run_id: non-empty-string}|null
	 */
	private static function parseManualLockHolder(string $cur): ?array
	{
		if (! \str_starts_with($cur, 'm:')) {
			return null;
		}
		$rest = \substr($cur, 2);
		$pos  = \strpos($rest, ':');
		if ($pos === false || $pos < 1) {
			return null;
		}
		$uid = (int) \substr($rest, 0, $pos);
		$run = \substr($rest, $pos + 1);
		if ($uid < 1 || $run === '') {
			return null;
		}

		return [ 'user_id' => $uid, 'run_id' => $run ];
	}

	public static function refresh(): void
	{
		$val = \get_transient(self::KEY);
		if ($val !== false && \is_string($val) && $val !== '') {
			\set_transient(self::KEY, $val, self::TTL_SECONDS);
		}
	}

	/**
	 * Release only if the transient still matches the holder (prevents stealing another run’s cleanup).
	 */
	public static function releaseCron(): void
	{
		if (\get_transient(self::KEY) === self::CRON_VALUE) {
			\delete_transient(self::KEY);
		}
	}

	/**
	 * @param non-empty-string $sanitizedRunId
	 */
	public static function releaseManual(int $userId, string $sanitizedRunId): void
	{
		$desired = self::manualValue($userId, $sanitizedRunId);
		if (\get_transient(self::KEY) === $desired) {
			\delete_transient(self::KEY);
		}
	}

	public static function isLocked(): bool
	{
		return \get_transient(self::KEY) !== false;
	}

	/**
	 * @param non-empty-string $sanitizedRunId
	 */
	public static function isHeldByManualRun(int $userId, string $sanitizedRunId): bool
	{
		return \get_transient(self::KEY) === self::manualValue($userId, $sanitizedRunId);
	}

	/**
	 * Removes the sync lock regardless of holder (cron or manual). For wp-admin troubleshooting only.
	 *
	 * Call only when you know no sync should be running; an in-flight sync may behave unpredictably.
	 */
	public static function forceReleaseAll(): void
	{
		\delete_transient(self::KEY);
	}
}
