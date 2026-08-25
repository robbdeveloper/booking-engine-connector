<?php

declare(strict_types=1);

namespace BookingEngineConnector\Cache;

/**
 * Deletes plugin transients (API response cache and, optionally, operational keys).
 */
final class TransientPurge
{
	/**
	 * Transient names that are always deleted even if they are not present in the options table
	 * (covers persistent object cache hiding hashed-less static keys).
	 *
	 * @var list<string>
	 */
	private const KNOWN_STATIC_KEYS = [
		'bec_kross_access_token',
		'bec_kross_access_token_exp',
		'bec_kross_token_lock',
		'bec_unit_completeness_report',
	];

	/**
	 * Transient name prefixes that must not be deleted by the admin “Clear API cache” action.
	 *
	 * @return list<string>
	 */
	public static function protectedPrefixes(): array
	{
		$defaults = [
			'bec_sync_running_lock',
			'bec_sync_prog_',
			'bec_sync_result_',
			'bec_rename_gallery_result_',
			'bec_kross_booking_engine_refresh_notice_',
			'bec_connection_flash_',
			'bec_frontend_flash_',
		];

		/**
		 * @var mixed $filtered
		 */
		$filtered = \apply_filters('bec_transient_purge_protected_prefixes', $defaults);

		if (! \is_array($filtered)) {
			return $defaults;
		}

		$out = [];
		foreach ($filtered as $prefix) {
			if (! \is_string($prefix) || $prefix === '') {
				continue;
			}
			$out[] = $prefix;
		}

		return $out !== [] ? $out : $defaults;
	}

	/**
	 * Purge API/request caches. Skips sync lock, progress, result, and admin flash transients.
	 */
	public static function purgeApiCache(): int
	{
		return self::purge(false);
	}

	/**
	 * Purge every plugin transient (used on uninstall).
	 */
	public static function purgeAll(): int
	{
		return self::purge(true);
	}

	private static function purge(bool $includeProtected): int
	{
		global $wpdb;

		$like = $wpdb->esc_like('_transient_bec_') . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		if (! \is_array($names)) {
			$names = [];
		}

		$deleted = 0;
		$seen    = [];

		foreach ($names as $optionName) {
			$optionName = (string) $optionName;
			if (! \str_starts_with($optionName, '_transient_') || \str_starts_with($optionName, '_transient_timeout_')) {
				continue;
			}

			$key = \substr($optionName, \strlen('_transient_'));
			if ($key === '') {
				continue;
			}

			if (! $includeProtected && self::isProtected($key)) {
				continue;
			}

			if (\delete_transient($key)) {
				++$deleted;
			}
			$seen[ $key ] = true;
		}

		foreach (self::KNOWN_STATIC_KEYS as $key) {
			if (isset($seen[ $key ])) {
				continue;
			}

			if (! $includeProtected && self::isProtected($key)) {
				continue;
			}

			if (\delete_transient($key)) {
				++$deleted;
			}
		}

		return $deleted;
	}

	private static function isProtected(string $key): bool
	{
		foreach (self::protectedPrefixes() as $prefix) {
			if ($key === $prefix || \str_starts_with($key, $prefix)) {
				return true;
			}
		}

		return false;
	}
}
