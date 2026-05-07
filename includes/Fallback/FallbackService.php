<?php

declare(strict_types=1);

namespace BookingEngineConnector\Fallback;

use BookingEngineConnector\Providers\Contracts\ProviderErrorCategory;

/**
 * Decides when to show contact fallback instead of online booking (TASK-FB-001 / FB-003).
 */
final class FallbackService
{
	/**
	 * True when fallback is globally enabled and “Always use fallback” (force) is on.
	 */
	public static function isAlwaysOn(): bool
	{
		return (bool) \get_option(FallbackSettings::OPTION_ENABLED, true)
			&& (bool) \get_option(FallbackSettings::OPTION_FORCE, false);
	}

	/**
	 * @param mixed $quote From {@see \BookingEngineConnector\Search\QuoteService::getQuote()}.
	 */
	public static function shouldDisplay($quote): bool
	{
		if (! (bool) \get_option(FallbackSettings::OPTION_ENABLED, true)) {
			return false;
		}

		if ((bool) \get_option(FallbackSettings::OPTION_FORCE, false)) {
			return true;
		}

		if ($quote instanceof \WP_Error) {
			return self::errorMatchesTriggers($quote);
		}

		if (\is_array($quote) && empty($quote['available']) && (bool) \get_option(FallbackSettings::OPTION_EMPTY_QUOTE, false)) {
			return true;
		}

		return (bool) \apply_filters('bec_fallback_should_display', false, $quote);
	}

	private static function errorMatchesTriggers(\WP_Error $error): bool
	{
		$data = $error->get_error_data();
		$cat  = ProviderErrorCategory::UNKNOWN;
		if (\is_array($data) && isset($data['category'])) {
			$cat = (string) $data['category'];
		}

		$triggers = self::getTriggerCategories();
		if ($triggers === []) {
			return false;
		}

		return \in_array($cat, $triggers, true);
	}

	/**
	 * @return list<string>
	 */
	public static function getTriggerCategories(): array
	{
		$default = [ProviderErrorCategory::RATE_LIMIT, ProviderErrorCategory::SERVER_ERROR, ProviderErrorCategory::AUTH];

		$raw = \get_option(FallbackSettings::OPTION_TRIGGER_CATEGORIES, false);
		if ($raw === false || $raw === '') {
			return $default;
		}

		$decoded = \json_decode((string) $raw, true);
		if (! \is_array($decoded)) {
			return $default;
		}

		$out = [];
		foreach ($decoded as $item) {
			if (\is_string($item) && $item !== '') {
				$out[] = $item;
			}
		}

		return $out;
	}
}
