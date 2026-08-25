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
	 * Engines discovered from the last live `/rooms/get-room-types` normalization.
	 *
	 * @return list<string>
	 */
	public static function getCachedAvailableEngines(): array
	{
		$stored = \get_option(self::OPTION_AVAILABLE_BOOKING_ENGINES, []);

		return self::sanitizeEngineSlugList(\is_array($stored) ? $stored : []);
	}

	/**
	 * Replaces the cached catalog with the given slugs.
	 *
	 * @param list<string>|array<int|string, mixed> $engines
	 */
	public static function setCachedAvailableEngines(array $engines): void
	{
		$list = self::sanitizeEngineSlugList($engines);
		if ($list === []) {
			\delete_option(self::OPTION_AVAILABLE_BOOKING_ENGINES);

			return;
		}

		\update_option(self::OPTION_AVAILABLE_BOOKING_ENGINES, $list, false);
	}

	/**
	 * Replaces the cached catalog from normalized rows and prunes stale selections.
	 *
	 * Skipped while Kross test mode is active so placeholder `be_enabled` slugs do not overwrite live data.
	 *
	 * @param array<int, array<string, mixed>> $normalizedRows Output of {@see KrossProvider} normalize step (includes `raw`)
	 */
	public static function updateAvailableEnginesFromNormalizedRows(array $normalizedRows): void
	{
		if (KrossTestMode::isEnabled()) {
			return;
		}

		$discovered = [];

		foreach ($normalizedRows as $row) {
			if (! \is_array($row)) {
				continue;
			}
			$raw = isset($row['raw']) && \is_array($row['raw']) ? $row['raw'] : [];

			foreach (self::extractBeEnabledSlugsFromRaw($raw) as $slug) {
				$discovered[] = $slug;
			}
		}

		self::setCachedAvailableEngines($discovered);
		self::pruneSelectedBookingEnginesToAvailable();
	}

	/**
	 * Drops selected slugs that are no longer in the cached available catalog.
	 */
	public static function pruneSelectedBookingEnginesToAvailable(): void
	{
		$available = self::getCachedAvailableEngines();
		$selected  = self::getSelectedBookingEngines();

		if ($selected === []) {
			return;
		}

		if ($available === []) {
			self::setSelectedBookingEngines([]);

			return;
		}

		$availableFlip = \array_fill_keys($available, true);
		$pruned        = [];

		foreach ($selected as $slug) {
			if (isset($availableFlip[ $slug ])) {
				$pruned[] = $slug;
			}
		}

		self::setSelectedBookingEngines($pruned);
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
