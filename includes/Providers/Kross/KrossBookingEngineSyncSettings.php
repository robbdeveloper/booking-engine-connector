<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * Kross-only: cached list of booking engine slugs (`be_enabled` from room types)
 * and which of those engines to include when syncing unit posts.
 */
final class KrossBookingEngineSyncSettings
{
	public const OPTION_SELECTED_BOOKING_ENGINES  = 'bec_kross_sync_booking_engines';

	public const OPTION_AVAILABLE_BOOKING_ENGINES = 'bec_kross_available_booking_engines';

	/**
	 * @return list<string>
	 */
	public static function getSelectedBookingEngines(): array
	{
		$stored = \get_option(self::OPTION_SELECTED_BOOKING_ENGINES, []);

		return self::sanitizeEngineSlugList(\is_array($stored) ? $stored : []);
	}

	/**
	 * @param list<string>|array<int|string, mixed> $engines
	 */
	public static function setSelectedBookingEngines(array $engines): void
	{
		$list = self::sanitizeEngineSlugList($engines);
		if ($list === []) {
			\delete_option(self::OPTION_SELECTED_BOOKING_ENGINES);

			return;
		}

		\update_option(self::OPTION_SELECTED_BOOKING_ENGINES, $list, false);
	}

	/**
	 * Engines discovered from the last `/rooms/get-room-types` normalization (merged across rows).
	 *
	 * @return list<string>
	 */
	public static function getCachedAvailableEngines(): array
	{
		$stored = \get_option(self::OPTION_AVAILABLE_BOOKING_ENGINES, []);

		return self::sanitizeEngineSlugList(\is_array($stored) ? $stored : []);
	}

	/**
	 * Merges new slugs into the cached catalog and persists.
	 *
	 * @param list<string>|array<int|string, mixed> $engines
	 */
	public static function mergeIntoCachedAvailableEngines(array $engines): void
	{
		$incoming = self::sanitizeEngineSlugList($engines);
		if ($incoming === []) {
			return;
		}

		$merged = \array_unique(
			\array_merge(self::getCachedAvailableEngines(), $incoming)
		);
		\sort($merged, \SORT_STRING);

		\update_option(self::OPTION_AVAILABLE_BOOKING_ENGINES, $merged, false);
	}

	/**
	 * @param array<int, array<string, mixed>> $normalizedRows Output of {@see KrossProvider} normalize step (includes `raw`)
	 */
	public static function updateAvailableEnginesFromNormalizedRows(array $normalizedRows): void
	{
		$merged = [];

		foreach ($normalizedRows as $row) {
			if (! \is_array($row)) {
				continue;
			}
			$raw = isset($row['raw']) && \is_array($row['raw']) ? $row['raw'] : [];

			foreach (self::extractBeEnabledSlugsFromRaw($raw) as $slug) {
				$merged[] = $slug;
			}
		}

		self::mergeIntoCachedAvailableEngines($merged);
	}

	/**
	 * Empty selection = sync all Kross units (no filter).
	 *
	 * @param array<string, mixed> $normalizedRow Includes `raw` with Kross payload
	 */
	public static function normalizedRowPassesSyncSelection(array $normalizedRow): bool
	{
		$selected = self::getSelectedBookingEngines();

		if ($selected === []) {
			return true;
		}

		$raw    = isset($normalizedRow['raw']) && \is_array($normalizedRow['raw'])
			? $normalizedRow['raw']
			: [];
		$rowSet = self::extractBeEnabledSlugsFromRaw($raw);

		return ! empty(\array_intersect($selected, $rowSet));
	}

	/**
	 * @param array<string, mixed> $rawRoomType Kross room type row from API
	 *
	 * @return list<string>
	 */
	public static function extractBeEnabledSlugsFromRaw(array $rawRoomType): array
	{
		if (! isset($rawRoomType['be_enabled'])) {
			return [];
		}

		$tag = $rawRoomType['be_enabled'];

		if (\is_string($tag)) {
			$slug = self::sanitizeOneEngineSlug($tag);

			return $slug !== '' ? [ $slug ] : [];
		}

		if (! \is_array($tag)) {
			return [];
		}

		$out = [];

		foreach ($tag as $entry) {
			if (\is_string($entry) || \is_numeric($entry)) {
				$slug = self::sanitizeOneEngineSlug((string) $entry);

				if ($slug !== '') {
					$out[] = $slug;
				}
			}
		}

		return \array_values(\array_unique($out));
	}

	/**
	 * @param list<string>|array<int|string, mixed> $list
	 *
	 * @return list<string>
	 */
	private static function sanitizeEngineSlugList(array $list): array
	{
		$out = [];

		foreach ($list as $entry) {
			if (! \is_string($entry) && ! \is_numeric($entry)) {
				continue;
			}
			$slug = self::sanitizeOneEngineSlug((string) $entry);

			if ($slug !== '') {
				$out[] = $slug;
			}
		}

		$out = \array_values(\array_unique($out));
		\sort($out, \SORT_STRING);

		return $out;
	}

	private static function sanitizeOneEngineSlug(string $slug): string
	{
		$slug = \trim($slug);

		if ($slug === '') {
			return '';
		}

		return (string) \sanitize_key($slug);
	}

}
