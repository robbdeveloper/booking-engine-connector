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
	/** @var list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}>|null */
	private static ?array $segmentsMemo = null;

	/**
	 * Calendar hint payload for the enhanced search daterangepicker.
	 *
	 * @return array{
	 *     active: bool,
	 *     unavailable_ranges: list<array{from: string, to: string}>,
	 *     invalid_checkin_ranges: list<array{from: string, to: string}>,
	 *     min_nights: int,
	 *     horizon_to: string
	 * }
	 */
	public static function getCalendarAvailabilityHints(?int $unitPostId): array
	{
		$empty = [
			'active'                 => false,
			'unavailable_ranges'     => [],
			'invalid_checkin_ranges' => [],
			'min_nights'             => self::resolveMinNights(),
			'horizon_to'             => self::horizonDateTo(),
		];

		if (! self::isFeatureActive()) {
			return $empty;
		}

		$mode = (string) \apply_filters(
			'bec_search_calendar_availability_mode',
			SearchSettings::getCalendarAvailabilityMode()
		);

		if ($mode === SearchSettings::CALENDAR_AVAILABILITY_SINGLE_UNIT) {
			$resolvedUnitId = self::resolveUnitPostId($unitPostId);
			if ($resolvedUnitId < 1) {
				return $empty;
			}

			$externalId = (string) \get_post_meta($resolvedUnitId, 'bec_external_id', true);
			if ($externalId === '') {
				return $empty;
			}

			$provider = ProviderRegistry::getProvider();
			if (! $provider instanceof CalendarAvailabilityProviderInterface) {
				return $empty;
			}

			$dateFrom   = self::horizonDateFrom();
			$dateTo     = self::horizonDateTo();
			$minNights  = self::resolveMinNights();
			$segments   = self::getSegments($provider);
			$ranges     = CalendarAvailabilityRanges::unavailableRangesForUnit(
				$segments,
				$externalId,
				$dateFrom,
				$dateTo
			);
			$checkinRanges = CalendarAvailabilityRanges::invalidCheckinRangesForUnit(
				$segments,
				$externalId,
				$dateFrom,
				$dateTo,
				$minNights
			);
		} elseif ($mode === SearchSettings::CALENDAR_AVAILABILITY_ALL_SEARCH) {
			$provider = ProviderRegistry::getProvider();
			if (! $provider instanceof CalendarAvailabilityProviderInterface) {
				return $empty;
			}

			$dateFrom   = self::horizonDateFrom();
			$dateTo     = self::horizonDateTo();
			$minNights  = self::resolveMinNights();
			$segments   = self::getSegments($provider);
			$ranges     = CalendarAvailabilityRanges::unavailableRangesGlobalUnion($segments, $dateFrom, $dateTo);
			$checkinRanges = CalendarAvailabilityRanges::invalidCheckinRangesGlobalUnion(
				$segments,
				$dateFrom,
				$dateTo,
				$minNights
			);
		} else {
			return $empty;
		}

		/**
		 * @var list<array{from: string, to: string}> $ranges
		 */
		$ranges = (array) \apply_filters('bec_calendar_unavailable_ranges', $ranges, $unitPostId);

		/**
		 * @var list<array{from: string, to: string}> $checkinRanges
		 */
		$checkinRanges = (array) \apply_filters('bec_calendar_invalid_checkin_ranges', $checkinRanges, $unitPostId);

		return [
			'active'                 => true,
			'unavailable_ranges'     => $ranges,
			'invalid_checkin_ranges' => $checkinRanges,
			'min_nights'             => $minNights,
			'horizon_to'             => $dateTo,
		];
	}

	/**
	 * @return list<array{from: string, to: string}>
	 */
	public static function getUnavailableRangesForContext(?int $unitPostId): array
	{
		$hints = self::getCalendarAvailabilityHints($unitPostId);

		return $hints['unavailable_ranges'];
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

	public static function getHorizonDateTo(): string
	{
		return self::horizonDateTo();
	}

	private static function resolveMinNights(): int
	{
		$min = (int) \apply_filters('bec_search_min_nights', SearchSettings::DEFAULT_MIN_NIGHTS, null);

		return \max(1, $min);
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
	 * @return list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}>
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

		$segments = $provider->normalizeBulkAvailability($bulk);

		/**
		 * @var list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}> $segments
		 */
		$segments = (array) \apply_filters('bec_calendar_availability_segments', $segments);

		self::$segmentsMemo = $segments;

		return self::$segmentsMemo;
	}
}
