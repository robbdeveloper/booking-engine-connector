<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

/**
 * WP-Cron scheduling for periodic sync (SPEC-SYNC, SPEC-INFRA).
 */
final class SyncCron
{
	public const CRON_HOOK = 'bec_run_scheduled_sync';

	public const OPTION_INTERVAL_HOURS = 'bec_sync_interval_hours';

	public static function register(): void
	{
		\add_filter('cron_schedules', [self::class, 'registerSchedule']);
		\add_action(self::CRON_HOOK, [self::class, 'run']);
		\add_action('init', [self::class, 'ensureScheduled'], 99);
	}

	public static function activate(): void
	{
		self::schedule();
	}

	public static function clear(): void
	{
		$ts = \wp_next_scheduled(self::CRON_HOOK);
		if ($ts) {
			\wp_unschedule_event($ts, self::CRON_HOOK);
		}
	}

	public static function schedule(): void
	{
		if (\wp_next_scheduled(self::CRON_HOOK)) {
			return;
		}

		\wp_schedule_event(\time() + 60, 'bec_sync_interval', self::CRON_HOOK);
	}

	public static function reschedule(): void
	{
		self::clear();
		self::schedule();
	}

	public static function ensureScheduled(): void
	{
		if (\wp_next_scheduled(self::CRON_HOOK)) {
			return;
		}

		self::schedule();
	}

	/**
	 * @param array<string, array<string, int|string>> $schedules
	 * @return array<string, array<string, int|string>>
	 */
	public static function registerSchedule(array $schedules): array
	{
		$hours = \max(1, (int) \get_option(self::OPTION_INTERVAL_HOURS, 6));

		$schedules['bec_sync_interval'] = [
			'interval' => $hours * \HOUR_IN_SECONDS,
			'display'  => \sprintf(
				/* translators: %d hours */
				\__('Every %d hours (Booking Engine)', 'booking-engine-connector'),
				$hours
			),
		];

		return $schedules;
	}

	public static function run(): void
	{
		$service = new SyncService();

		try {
			$service->syncAll();
		} catch (\Throwable $e) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort observability for cron.
			\error_log('BEC sync cron: ' . $e->getMessage());
		}
	}
}
