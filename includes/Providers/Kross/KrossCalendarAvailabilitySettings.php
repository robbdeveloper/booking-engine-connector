<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * Kross-only: calendar availability source (widget get-ava vs v5 get-availability).
 */
final class KrossCalendarAvailabilitySettings
{
	public const OPTION_METHOD       = 'bec_kross_calendar_availability_method';

	public const OPTION_GET_AVA_BE_ID = 'bec_kross_calendar_get_ava_be_id';

	public const METHOD_GET_AVA = 'get-ava';

	public const METHOD_GET_AVAILABILITY = 'get-availability';

	public static function getMethod(): string
	{
		$stored = \get_option(self::OPTION_METHOD, '');

		if (! \is_string($stored) || $stored === '') {
			return self::METHOD_GET_AVA;
		}

		$stored = \sanitize_key($stored);

		if ($stored === self::METHOD_GET_AVAILABILITY) {
			return self::METHOD_GET_AVAILABILITY;
		}

		return self::METHOD_GET_AVA;
	}

	public static function isGetAvaMethod(): bool
	{
		return self::getMethod() === self::METHOD_GET_AVA;
	}

	public static function setMethod(string $method): void
	{
		if ($method === self::METHOD_GET_AVAILABILITY) {
			\update_option(self::OPTION_METHOD, self::METHOD_GET_AVAILABILITY, false);

			return;
		}

		\update_option(self::OPTION_METHOD, self::METHOD_GET_AVA, false);
	}

	public static function getGetAvaBeId(): string
	{
		$stored = (string) \get_option(self::OPTION_GET_AVA_BE_ID, '');

		return self::sanitizeBeId($stored);
	}

	public static function setGetAvaBeId(string $beId): void
	{
		$beId = self::sanitizeBeId($beId);

		if ($beId === '') {
			\delete_option(self::OPTION_GET_AVA_BE_ID);

			return;
		}

		\update_option(self::OPTION_GET_AVA_BE_ID, $beId, false);
	}

	/**
	 * Cached booking-engine slugs for the admin select (selected sync engines first).
	 *
	 * @return list<string>
	 */
	public static function getEngineOptionsForSelect(): array
	{
		$available = KrossBookingEngineSyncSettings::getCachedAvailableEngines();

		if ($available === []) {
			return [];
		}

		$selected = KrossBookingEngineSyncSettings::getSelectedBookingEngines();
		$ordered  = [];

		foreach ($selected as $slug) {
			if (\in_array($slug, $available, true)) {
				$ordered[] = $slug;
			}
		}

		foreach ($available as $slug) {
			if (! \in_array($slug, $ordered, true)) {
				$ordered[] = $slug;
			}
		}

		return $ordered;
	}

	private static function sanitizeBeId(string $beId): string
	{
		return (string) \sanitize_key(\trim($beId));
	}
}
