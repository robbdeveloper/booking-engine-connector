<?php

declare(strict_types=1);

namespace BookingEngineConnector\Integrations;

use BookingEngineConnector\Fallback\FallbackSettings;

/**
 * Optional WPML / Polylang helpers for option-backed strings gettext cannot replace at runtime.
 *
 * Fixed UI copy stays in PHP `__()` / POT. Values saved in {@see FallbackSettings} are registered as
 * translatable strings when those plugins are active.
 */
final class Multilingual
{
	/** WPML String Translation context (matches common WPML registration examples for plugins). */
	public const WPML_CONTEXT = 'Booking Engine Connector';

	/** Polylang group name for pll_register_string(). */
	public const PLL_GROUP = 'booking-engine-connector';

	public const STRING_FALLBACK_LINK_TEXT = 'fallback_link_text';

	public const STRING_FALLBACK_INLINE_CONTENT = 'fallback_inline_content';

	public static function register(): void
	{
		\add_action('init', [self::class, 'registerFallbackOptionStrings'], 20);
	}

	public static function registerFallbackOptionStrings(): void
	{
		self::registerStoredFallbackStrings();
	}

	public static function syncAfterFallbackSave(): void
	{
		self::registerStoredFallbackStrings();
	}

	public static function registerOptionString(string $name, string $value, bool $multiline): void
	{
		if ($value === '') {
			return;
		}

		\do_action('wpml_register_single_string', self::WPML_CONTEXT, $name, $value);

		if (\function_exists('pll_register_string')) {
			\pll_register_string($name, $value, self::PLL_GROUP, $multiline);
		}
	}

	public static function translateFallbackLinkText(string $stored): string
	{
		if ($stored === '') {
			return '';
		}

		return self::translateRegisteredString(self::STRING_FALLBACK_LINK_TEXT, $stored);
	}

	public static function translateFallbackInlineContent(string $stored): string
	{
		if ($stored === '') {
			return '';
		}

		return self::translateRegisteredString(self::STRING_FALLBACK_INLINE_CONTENT, $stored);
	}

	/**
	 * WordPress locale after `bec_provider_locale` (API locale hints, WPML/Polylang adjustments).
	 *
	 * @param string $context Discriminator for listeners (e.g. `kross_checkout`).
	 */
	public static function filteredSiteLocale(string $context = 'general'): string
	{
		$base = self::rawSiteLocale();
		$filtered = \apply_filters('bec_provider_locale', $base, $context);

		return \is_string($filtered) && $filtered !== '' ? $filtered : $base;
	}

	public static function rawSiteLocale(): string
	{
		return \function_exists('determine_locale') ? \determine_locale() : \get_locale();
	}

	private static function translateRegisteredString(string $name, string $sourceValue): string
	{
		if (\defined('ICL_SITEPRESS_VERSION')) {
			$t = \apply_filters('wpml_translate_single_string', $sourceValue, self::WPML_CONTEXT, $name);

			return \is_string($t) ? $t : $sourceValue;
		}

		if (\function_exists('pll__')) {
			return \pll__($sourceValue);
		}

		return $sourceValue;
	}

	private static function registerStoredFallbackStrings(): void
	{
		$link = (string) \get_option(FallbackSettings::OPTION_LINK_TEXT, '');
		if ($link !== '') {
			self::registerOptionString(self::STRING_FALLBACK_LINK_TEXT, $link, false);
		}

		$inline = (string) \get_option(FallbackSettings::OPTION_INLINE_CONTENT, '');
		if ($inline !== '') {
			self::registerOptionString(self::STRING_FALLBACK_INLINE_CONTENT, $inline, true);
		}
	}
}
