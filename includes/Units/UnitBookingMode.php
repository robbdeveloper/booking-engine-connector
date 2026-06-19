<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units;

/**
 * Reads canonical booking-mode flags from unit core meta.
 */
final class UnitBookingMode
{
	public static function isOnlyRequest(int $postId): bool
	{
		if ($postId < 1) {
			return false;
		}

		$raw = \get_post_meta($postId, 'bec_core_only_request', true);

		return self::parseBool($raw);
	}

	public static function startingFromAmount(int $postId): ?float
	{
		if ($postId < 1) {
			return null;
		}

		$raw = \get_post_meta($postId, 'bec_core_starting_from', true);
		if ($raw === '' || $raw === false || $raw === null) {
			return null;
		}
		if (! \is_numeric($raw)) {
			return null;
		}

		return (float) ( $raw + 0 );
	}

	public static function startingFromCurrency(int $postId): string
	{
		/** @var string $currency */
		$currency = (string) \apply_filters('bec_core_starting_from_currency', '', $postId);

		return \trim($currency);
	}

	/**
	 * @param mixed $value
	 */
	private static function parseBool($value): bool
	{
		if ($value === null || $value === '') {
			return false;
		}
		if (\is_bool($value)) {
			return $value;
		}
		if (\is_string($value)) {
			return \in_array(\strtolower($value), ['1', 'true', 'yes', 'on'], true);
		}
		if (\is_int($value) || \is_float($value)) {
			return (int) $value !== 0;
		}

		return (bool) $value;
	}
}
