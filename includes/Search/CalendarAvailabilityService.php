<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Providers\Contracts\CalendarAvailabilityProviderInterface;
use BookingEngineConnector\Providers\Contracts\ProviderException;
use BookingEngineConnector\Providers\ProviderRegistry;

/**
 * Fetches bulk calendar availability via the active provider and derives unavailable date ranges.
 */
final class CalendarAvailabilityService
{
	/** @var list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool}>|null */
	private static ?array $segmentsMemo = null;

	/**
	 * @return list<array{from: string, to: string}>
	 */
	public static function getUnavailableRangesForContext(?int $unitPostId): array
	{
		$mode = (string) \apply_filters(
			'bec_search_calendar_availability_mode',
			SearchSettings::getCalendarAvailabilityMode()
		);
		if ($mode === SearchSettings::CALENDAR_AVAILABILITY_OFF) {
			return [];
		}

		$provider = ProviderRegistry::getProvider();
		if (! $provider instanceof CalendarAvailabilityProviderInterface) {
			return [];
		}

		$dateFrom = self::horizonDateFrom();
		$dateTo   = self::horizonDateTo();

		if ($mode === SearchSettings::CALENDAR_AVAILABILITY_SINGLE_UNIT) {
			$resolvedUnitId = self::resolveUnitPostId($unitPostId);
			if ($resolvedUnitId < 1) {
				return [];
			}

			$externalId = (string) \get_post_meta($resolvedUnitId, 'bec_external_id', true);
			if ($externalId === '') {
				return [];
			}

			$segments = self::getSegments($provider);
			$ranges   = CalendarAvailabilityRanges::unavailableRangesForUnit(
				$segments,
				$externalId,
				$dateFrom,
				$dateTo
			);
		} elseif ($mode === SearchSettings::CALENDAR_AVAILABILITY_ALL_SEARCH) {
			$segments = self::getSegments($provider);
			$ranges   = CalendarAvailabilityRanges::unavailableRangesGlobalUnion($segments, $dateFrom, $dateTo);
		} else {
			return [];
		}

		/**
		 * @var list<array{from: string, to: string}> $ranges
		 */
		$ranges = (array) \apply_filters('bec_calendar_unavailable_ranges', $ranges, $unitPostId);

		return $ranges;
	}

	public static function isFeatureActive(): bool
	{
		$mode = (string) \apply_filters(
			'bec_search_calendar_availability_mode',
			SearchSettings::getCalendarAvailabilityMode()
		);
		if ($mode === SearchSettings::CALENDAR_AVAILABILITY_OFF) {
			return false;
		}

		$provider = ProviderRegistry::getProvider();

		return $provider instanceof CalendarAvailabilityProviderInterface;
	}

	private static function resolveUnitPostId(?int $unitPostId): int
	{
		if ($unitPostId !== null && $unitPostId > 0) {
			return $unitPostId;
		}

		if (\is_singular(UnitPostType::getSlug())) {
			return (int) \get_the_ID();
		}

		return 0;
	}

	private static function horizonDateFrom(): string
	{
		return \gmdate('Y-m-d');
	}

	private static function horizonDateTo(): string
	{
		$maxDays = (int) \apply_filters('bec_daterangepicker_max_date_from_today', 730, null);
		if ($maxDays < 1) {
			$maxDays = 730;
		}

		return \gmdate('Y-m-d', \strtotime('+' . $maxDays . ' days'));
	}

	/**
	 * @return list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool}>
	 */
	private static function getSegments(CalendarAvailabilityProviderInterface $provider): array
	{
		if (self::$segmentsMemo !== null) {
			return self::$segmentsMemo;
		}

		$key = $provider->getBulkAvailabilityCacheKey();
		$ttl = (int) \apply_filters('bec_calendar_availability_cache_ttl', 5 * \MINUTE_IN_SECONDS);

		$bulk = \get_transient($key);
		if ($bulk === false) {
			try {
				$bulk = $provider->fetchBulkAvailability();
				if ($ttl > 0) {
					\set_transient($key, $bulk, $ttl);
				}
			} catch (ProviderException $e) {
				self::$segmentsMemo = [];

				return [];
			}
		}

		self::$segmentsMemo = $provider->normalizeBulkAvailability($bulk);

		return self::$segmentsMemo;
	}
}
