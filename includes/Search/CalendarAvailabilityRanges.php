<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

/**
 * Pure helpers for turning provider availability segments into daterangepicker ranges.
 */
final class CalendarAvailabilityRanges
{
	/**
	 * @param list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}> $segments
	 *
	 * @return list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}>
	 */
	public static function filterForUnit(array $segments, string $remoteUnitId): array
	{
		$out = [];
		foreach ($segments as $segment) {
			if ((string) ($segment['remote_unit_id'] ?? '') === $remoteUnitId) {
				$out[] = $segment;
			}
		}

		return $out;
	}

	/**
	 * Unavailable ranges for one unit: days not covered by an available segment within the horizon.
	 *
	 * @param list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}> $segments
	 *
	 * @return list<array{from: string, to: string}>
	 */
	public static function unavailableRangesForUnit(
		array $segments,
		string $remoteUnitId,
		string $dateFrom,
		string $dateTo
	): array {
		$unitSegments = self::filterForUnit($segments, $remoteUnitId);
		$availableDays = self::collectAvailableDays($unitSegments, $dateFrom, $dateTo);

		return self::complementToUnavailableRanges($availableDays, $dateFrom, $dateTo);
	}

	/**
	 * Union mode: a day is selectable when any unit has availability on that day.
	 *
	 * @param list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}> $segments
	 *
	 * @return list<array{from: string, to: string}>
	 */
	public static function unavailableRangesGlobalUnion(
		array $segments,
		string $dateFrom,
		string $dateTo
	): array {
		$availableDays = self::collectAvailableDays($segments, $dateFrom, $dateTo);

		return self::complementToUnavailableRanges($availableDays, $dateFrom, $dateTo);
	}

	/**
	 * Check-in dates that are inventory-available but cannot start a stay of at least minNights.
	 *
	 * @param list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}> $segments
	 *
	 * @return list<array{from: string, to: string}>
	 */
	public static function invalidCheckinRangesForUnit(
		array $segments,
		string $remoteUnitId,
		string $dateFrom,
		string $dateTo,
		int $minNights
	): array {
		if ($minNights <= 1) {
			return [];
		}

		$unitSegments  = self::filterForUnit($segments, $remoteUnitId);
		$availableDays = self::collectAvailableDays($unitSegments, $dateFrom, $dateTo);
		$minStayByDay  = self::collectMinStayByDay($unitSegments, $dateFrom, $dateTo, $minNights);

		return self::invalidCheckinDaysToRanges(
			self::collectInvalidCheckinDays($availableDays, $minStayByDay, $dateFrom, $dateTo),
			$dateFrom,
			$dateTo
		);
	}

	/**
	 * Union mode: a day is a valid check-in when any unit can satisfy the minimum stay from that day.
	 *
	 * @param list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}> $segments
	 *
	 * @return list<array{from: string, to: string}>
	 */
	public static function invalidCheckinRangesGlobalUnion(
		array $segments,
		string $dateFrom,
		string $dateTo,
		int $minNights
	): array {
		if ($minNights <= 1) {
			return [];
		}

		$anyAvailableDays = self::collectAvailableDays($segments, $dateFrom, $dateTo);
		$validCheckinDays = [];

		$unitIdList = [];
		foreach ($segments as $segment) {
			$id = (string) ($segment['remote_unit_id'] ?? '');
			if ($id !== '') {
				$unitIdList[] = $id;
			}
		}
		$unitIdList = \array_values(\array_unique($unitIdList));

		foreach ($unitIdList as $remoteUnitId) {
			$unitSegments  = self::filterForUnit($segments, $remoteUnitId);
			$availableDays = self::collectAvailableDays($unitSegments, $dateFrom, $dateTo);
			$minStayByDay  = self::collectMinStayByDay($unitSegments, $dateFrom, $dateTo, $minNights);

			foreach ($availableDays as $day => $_) {
				$required = $minStayByDay[$day] ?? $minNights;
				if (self::countForwardAvailableRun($availableDays, $day, $dateTo) >= $required) {
					$validCheckinDays[$day] = true;
				}
			}
		}

		$invalidDays = [];
		foreach (self::iterateDays($dateFrom, $dateTo) as $day) {
			if (isset($anyAvailableDays[$day]) && ! isset($validCheckinDays[$day])) {
				$invalidDays[$day] = true;
			}
		}

		return self::invalidCheckinDaysToRanges($invalidDays, $dateFrom, $dateTo);
	}

	/**
	 * @param array<string, true> $availableDays
	 * @param array<string, int>  $minStayByDay
	 *
	 * @return array<string, true>
	 */
	private static function collectInvalidCheckinDays(
		array $availableDays,
		array $minStayByDay,
		string $dateFrom,
		string $dateTo
	): array {
		$invalidDays = [];
		foreach (self::iterateDays($dateFrom, $dateTo) as $day) {
			if (! isset($availableDays[$day])) {
				continue;
			}

			$required = $minStayByDay[$day] ?? 1;
			if (self::countForwardAvailableRun($availableDays, $day, $dateTo) < $required) {
				$invalidDays[$day] = true;
			}
		}

		return $invalidDays;
	}

	/**
	 * @param array<string, true> $availableDays
	 */
	public static function countForwardAvailableRun(array $availableDays, string $day, string $clipTo): int
	{
		$startTs = \strtotime($day . ' 00:00:00 UTC');
		$endTs   = \strtotime($clipTo . ' 00:00:00 UTC');
		if ($startTs === false || $endTs === false || $startTs > $endTs) {
			return 0;
		}

		$count = 0;
		for ($ts = $startTs; $ts <= $endTs; $ts += \DAY_IN_SECONDS) {
			$currentDay = \gmdate('Y-m-d', $ts);
			if (! isset($availableDays[$currentDay])) {
				break;
			}
			++$count;
		}

		return $count;
	}

	/**
	 * @param list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}> $segments
	 *
	 * @return array<string, true>
	 */
	private static function collectAvailableDays(array $segments, string $clipFrom, string $clipTo): array
	{
		$availableDays = [];
		foreach ($segments as $segment) {
			if (empty($segment['available'])) {
				continue;
			}
			foreach (self::expandRange(
				(string) ($segment['date_from'] ?? ''),
				(string) ($segment['date_to'] ?? ''),
				$clipFrom,
				$clipTo
			) as $day) {
				$availableDays[$day] = true;
			}
		}

		return $availableDays;
	}

	/**
	 * @param list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}> $segments
	 *
	 * @return array<string, int>
	 */
	private static function collectMinStayByDay(
		array $segments,
		string $clipFrom,
		string $clipTo,
		int $globalMinNights
	): array {
		$minStayByDay = [];
		foreach ($segments as $segment) {
			if (empty($segment['available'])) {
				continue;
			}

			$segmentMin = isset($segment['minimum_stay']) ? (int) $segment['minimum_stay'] : 0;
			if ($segmentMin < 1) {
				$segmentMin = $globalMinNights;
			}

			foreach (self::expandRange(
				(string) ($segment['date_from'] ?? ''),
				(string) ($segment['date_to'] ?? ''),
				$clipFrom,
				$clipTo
			) as $day) {
				if (! isset($minStayByDay[$day]) || $segmentMin > $minStayByDay[$day]) {
					$minStayByDay[$day] = $segmentMin;
				}
			}
		}

		return $minStayByDay;
	}

	/**
	 * @param array<string, true> $availableDays
	 *
	 * @return list<array{from: string, to: string}>
	 */
	private static function complementToUnavailableRanges(
		array $availableDays,
		string $dateFrom,
		string $dateTo
	): array {
		$startTs = \strtotime($dateFrom . ' 00:00:00 UTC');
		$endTs   = \strtotime($dateTo . ' 00:00:00 UTC');
		if ($startTs === false || $endTs === false || $startTs > $endTs) {
			return [];
		}

		$ranges     = [];
		$rangeStart = null;
		for ($ts = $startTs; $ts <= $endTs; $ts += \DAY_IN_SECONDS) {
			$day      = \gmdate('Y-m-d', $ts);
			$isAvail  = isset($availableDays[$day]);
			if (! $isAvail) {
				if ($rangeStart === null) {
					$rangeStart = $day;
				}
				continue;
			}

			if ($rangeStart !== null) {
				$ranges[]   = [
					'from' => $rangeStart,
					'to'   => \gmdate('Y-m-d', $ts - \DAY_IN_SECONDS),
				];
				$rangeStart = null;
			}
		}

		if ($rangeStart !== null) {
			$ranges[] = [
				'from' => $rangeStart,
				'to'   => $dateTo,
			];
		}

		return self::mergeRanges($ranges);
	}

	/**
	 * @param array<string, true> $invalidDays
	 *
	 * @return list<array{from: string, to: string}>
	 */
	private static function invalidCheckinDaysToRanges(
		array $invalidDays,
		string $dateFrom,
		string $dateTo
	): array {
		if ($invalidDays === []) {
			return [];
		}

		$ranges     = [];
		$rangeStart = null;
		foreach (self::iterateDays($dateFrom, $dateTo) as $day) {
			$isInvalid = isset($invalidDays[$day]);
			if ($isInvalid) {
				if ($rangeStart === null) {
					$rangeStart = $day;
				}
				continue;
			}

			if ($rangeStart !== null) {
				$prevTs = \strtotime($day . ' 00:00:00 UTC');
				if ($prevTs !== false) {
					$ranges[] = [
						'from' => $rangeStart,
						'to'   => \gmdate('Y-m-d', $prevTs - \DAY_IN_SECONDS),
					];
				}
				$rangeStart = null;
			}
		}

		if ($rangeStart !== null) {
			$ranges[] = [
				'from' => $rangeStart,
				'to'   => $dateTo,
			];
		}

		return self::mergeRanges($ranges);
	}

	/**
	 * @return list<string>
	 */
	private static function iterateDays(string $dateFrom, string $dateTo): array
	{
		$startTs = \strtotime($dateFrom . ' 00:00:00 UTC');
		$endTs   = \strtotime($dateTo . ' 00:00:00 UTC');
		if ($startTs === false || $endTs === false || $startTs > $endTs) {
			return [];
		}

		$days = [];
		for ($ts = $startTs; $ts <= $endTs; $ts += \DAY_IN_SECONDS) {
			$days[] = \gmdate('Y-m-d', $ts);
		}

		return $days;
	}

	/**
	 * @return list<string>
	 */
	private static function expandRange(string $from, string $to, string $clipFrom, string $clipTo): array
	{
		if ($from === '' || $to === '') {
			return [];
		}

		$startTs = \strtotime($from . ' 00:00:00 UTC');
		$endTs   = \strtotime($to . ' 00:00:00 UTC');
		$clipStartTs = \strtotime($clipFrom . ' 00:00:00 UTC');
		$clipEndTs   = \strtotime($clipTo . ' 00:00:00 UTC');
		if ($startTs === false || $endTs === false || $clipStartTs === false || $clipEndTs === false) {
			return [];
		}

		$startTs = \max($startTs, $clipStartTs);
		$endTs   = \min($endTs, $clipEndTs);
		if ($startTs > $endTs) {
			return [];
		}

		$days = [];
		for ($ts = $startTs; $ts <= $endTs; $ts += \DAY_IN_SECONDS) {
			$days[] = \gmdate('Y-m-d', $ts);
		}

		return $days;
	}

	/**
	 * @param list<array{from: string, to: string}> $ranges
	 *
	 * @return list<array{from: string, to: string}>
	 */
	private static function mergeRanges(array $ranges): array
	{
		if ($ranges === []) {
			return [];
		}

		\usort(
			$ranges,
			static function (array $a, array $b): int {
				return \strcmp((string) ($a['from'] ?? ''), (string) ($b['from'] ?? ''));
			}
		);

		$merged   = [];
		$current  = $ranges[0];
		$count    = \count($ranges);
		for ($i = 1; $i < $count; ++$i) {
			$next = $ranges[$i];
			$curEndTs   = \strtotime((string) ($current['to'] ?? '') . ' 00:00:00 UTC');
			$nextStartTs = \strtotime((string) ($next['from'] ?? '') . ' 00:00:00 UTC');
			if ($curEndTs === false || $nextStartTs === false) {
				$merged[] = $current;
				$current  = $next;
				continue;
			}

			$adjacentStart = \gmdate('Y-m-d', $curEndTs + \DAY_IN_SECONDS);
			if ($adjacentStart >= (string) ($next['from'] ?? '')) {
				if ((string) ($next['to'] ?? '') > (string) ($current['to'] ?? '')) {
					$current['to'] = (string) $next['to'];
				}
				continue;
			}

			$merged[] = $current;
			$current  = $next;
		}

		$merged[] = $current;

		return $merged;
	}
}
