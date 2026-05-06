<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

use BookingEngineConnector\Providers\Contracts\SearchGuestFieldMode;

/**
 * WordPress options and filters for the public search form (guest fields, single-unit auto form).
 *
 * Admin UI: {@see \BookingEngineConnector\Admin\Settings\ConnectionPage}.
 */
final class SearchSettings
{
	/** @var string Value: follow {@see \BookingEngineConnector\Providers\Contracts\ProviderInterface} defaults. */
	public const GUEST_MODE_PROVIDER = 'provider';

	/** @var string Force single pax / “Guests” field only ({@see SearchContext::PARAM_TOTAL_GUESTS}). */
	public const GUEST_MODE_TOTAL = 'total';

	/** @var string Force adults + children fields. */
	public const GUEST_MODE_BREAKDOWN = 'breakdown';

	/** @var string Follow provider’s {@see \BookingEngineConnector\Providers\Contracts\ProviderInterface::requiresChildrenAges()}. */
	public const CHILD_AGES_PROVIDER = 'provider';

	/** @var string In breakdown mode, show one age per child. */
	public const CHILD_AGES_YES = 'yes';

	/** @var string In breakdown mode, do not collect child ages. */
	public const CHILD_AGES_NO = 'no';

	public const OPTION_GUEST_INPUT_MODE = 'bec_search_guest_input_mode';

	public const OPTION_CHILD_AGES_MODE = 'bec_search_child_ages_mode';

	/** Stored as 1 or 0; default off — use `[bec_search]` for manual placement. */
	public const OPTION_AUTO_APPEND_SEARCH_FORM_SINGLE_UNIT = 'bec_auto_append_search_form_single_unit';

	public static function register(): void
	{
		\add_filter('bec_search_guest_field_mode', [self::class, 'filterGuestFieldMode'], 20, 2);
		\add_filter('bec_provider_requires_children_ages', [self::class, 'filterRequiresChildAges'], 20, 2);
	}

	/**
	 * @return self::GUEST_MODE_*
	 */
	public static function getGuestInputModeOption(): string
	{
		$raw = (string) \get_option(self::OPTION_GUEST_INPUT_MODE, self::GUEST_MODE_PROVIDER);
		$raw = \sanitize_key($raw);
		$allowed = [self::GUEST_MODE_PROVIDER, self::GUEST_MODE_TOTAL, self::GUEST_MODE_BREAKDOWN];

		return \in_array($raw, $allowed, true) ? $raw : self::GUEST_MODE_PROVIDER;
	}

	/**
	 * @return self::CHILD_AGES_*
	 */
	public static function getChildAgesModeOption(): string
	{
		$raw = (string) \get_option(self::OPTION_CHILD_AGES_MODE, self::CHILD_AGES_PROVIDER);
		$raw = \sanitize_key($raw);
		$allowed = [self::CHILD_AGES_PROVIDER, self::CHILD_AGES_YES, self::CHILD_AGES_NO];

		return \in_array($raw, $allowed, true) ? $raw : self::CHILD_AGES_PROVIDER;
	}

	public static function isAutoAppendSearchFormOnSingleUnit(): bool
	{
		return (int) \get_option(self::OPTION_AUTO_APPEND_SEARCH_FORM_SINGLE_UNIT, 0) === 1;
	}

	/**
	 * @param mixed $ctx
	 */
	public static function filterGuestFieldMode(string $providerDefault, $ctx): string
	{
		unset($ctx);
		switch (self::getGuestInputModeOption()) {
			case self::GUEST_MODE_TOTAL:
				return SearchGuestFieldMode::TOTAL;
			case self::GUEST_MODE_BREAKDOWN:
				return SearchGuestFieldMode::BREAKDOWN;
			default:
				return $providerDefault;
		}
	}

	/**
	 * @param mixed $ctx
	 */
	public static function filterRequiresChildAges(bool $providerDefault, $ctx): bool
	{
		unset($ctx);
		switch (self::getChildAgesModeOption()) {
			case self::CHILD_AGES_YES:
				return true;
			case self::CHILD_AGES_NO:
				return false;
			default:
				return $providerDefault;
		}
	}
}
