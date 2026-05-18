<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

/**
 * Durable manual batch sync state (admin AJAX start/step), per user + run id.
 *
 * Stored in non-autoloaded options (not transients) so large payloads and long runs are supported.
 */
final class SyncManualBatchState
{
	private const STATE_VERSION = 1;

	private const STALE_AFTER_SECONDS = 86400;

	/**
	 * @return non-empty-string
	 */
	public static function optionName(int $userId, string $runId): string
	{
		$sanitized = SyncProgressReporter::sanitizeRunId($runId);

		return 'bec_sync_mbatch_' . $userId . '_' . $sanitized;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get(int $userId, string $runId): ?array
	{
		$name = self::optionName($userId, $runId);
		$raw  = \get_option($name, false);
		if ($raw === false || ! \is_array($raw)) {
			return null;
		}

		if ((int) ( $raw['version'] ?? 0 ) !== self::STATE_VERSION) {
			self::delete($userId, $runId);

			return null;
		}

		$updated = (int) ( $raw['updated_at'] ?? 0 );
		if ($updated > 0 && ( \time() - $updated ) > self::STALE_AFTER_SECONDS) {
			self::delete($userId, $runId);

			return null;
		}

		return $raw;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function set(int $userId, string $runId, array $data): void
	{
		$data['version']    = self::STATE_VERSION;
		$data['updated_at'] = \time();
		if (! isset($data['started_at']) || ! \is_int($data['started_at']) || $data['started_at'] < 1) {
			$prev = self::get($userId, $runId);
			if (\is_array($prev) && isset($prev['started_at']) && \is_int($prev['started_at'])) {
				$data['started_at'] = $prev['started_at'];
			} else {
				$data['started_at'] = $data['updated_at'];
			}
		}

		\update_option(self::optionName($userId, $runId), $data, false);
	}

	public static function delete(int $userId, string $runId): void
	{
		\delete_option(self::optionName($userId, $runId));
	}
}