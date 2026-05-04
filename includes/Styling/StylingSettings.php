<?php

declare(strict_types=1);

namespace BookingEngineConnector\Styling;

/**
 * Global shortcode styling options (admin + inline CSS on frontend).
 */
final class StylingSettings
{
	public const OPTION_SEARCH_PRESET = 'bec_styling_search_preset';

	public const OPTION_BOOKING_SUMMARY_PRESET = 'bec_styling_booking_summary_preset';

	public const OPTION_THEME_VARIABLES_CSS = 'bec_styling_theme_variables_css';

	public const OPTION_SEARCH_EXTRA_CSS = 'bec_styling_search_extra_css';

	public const OPTION_SUMMARY_EXTRA_CSS = 'bec_styling_summary_extra_css';

	public const OPTION_ACCORDION_INCLUSIONS = 'bec_styling_accordion_inclusions';

	public const OPTION_ACCORDION_CONDITIONS = 'bec_styling_accordion_conditions';

	/** Enhanced bar + daterange (default, matches previous plugin behaviour). */
	public const SEARCH_PRESET_ENHANCED = 'enhanced';

	/** Classic stacked fields + native date inputs. */
	public const SEARCH_PRESET_CLASSIC = 'classic';

	/** Summary layout: search on top under desktop shell (historic markup). */
	public const BOOKING_SUMMARY_PRESET_DEFAULT = 'default';

	/** Summary layout: search after main panel content on desktop; mobile drawer mirrors order. */
	public const BOOKING_SUMMARY_PRESET_COMPACT = 'compact';

	private const ALLOWED_SEARCH_PRESETS = [
		self::SEARCH_PRESET_ENHANCED,
		self::SEARCH_PRESET_CLASSIC,
	];

	private const ALLOWED_BOOKING_SUMMARY_PRESETS = [
		self::BOOKING_SUMMARY_PRESET_DEFAULT,
		self::BOOKING_SUMMARY_PRESET_COMPACT,
	];

	private const MAX_CSS_LENGTH = 200000;

	public static function register(): void
	{
		// Reserved for filters if needed later.
	}

	/**
	 * @return self::SEARCH_PRESET_*
	 */
	public static function getSearchPreset(): string
	{
		$raw = (string) \get_option(self::OPTION_SEARCH_PRESET, self::SEARCH_PRESET_ENHANCED);
		$raw = \sanitize_key($raw);

		return \in_array($raw, self::ALLOWED_SEARCH_PRESETS, true) ? $raw : self::SEARCH_PRESET_ENHANCED;
	}

	/**
	 * @return self::BOOKING_SUMMARY_PRESET_*
	 */
	public static function getBookingSummaryPreset(): string
	{
		$raw = (string) \get_option(self::OPTION_BOOKING_SUMMARY_PRESET, self::BOOKING_SUMMARY_PRESET_DEFAULT);
		$raw = \sanitize_key($raw);

		return \in_array($raw, self::ALLOWED_BOOKING_SUMMARY_PRESETS, true) ? $raw : self::BOOKING_SUMMARY_PRESET_DEFAULT;
	}

	public static function isAccordionInclusionsEnabled(): bool
	{
		$default = true;

		return (bool) \get_option(self::OPTION_ACCORDION_INCLUSIONS, $default);
	}

	public static function isAccordionConditionsEnabled(): bool
	{
		$default = true;

		return (bool) \get_option(self::OPTION_ACCORDION_CONDITIONS, $default);
	}

	public static function getThemeVariablesCssRaw(): string
	{
		return (string) \get_option(self::OPTION_THEME_VARIABLES_CSS, '');
	}

	public static function getSearchExtraCssRaw(): string
	{
		return (string) \get_option(self::OPTION_SEARCH_EXTRA_CSS, '');
	}

	public static function getSummaryExtraCssRaw(): string
	{
		return (string) \get_option(self::OPTION_SUMMARY_EXTRA_CSS, '');
	}

	public static function sanitizeCssBlock(string $css): string
	{
		if ($css === '') {
			return '';
		}
		$css = \str_replace("\0", '', $css);
		if (\strlen($css) > self::MAX_CSS_LENGTH) {
			$css = \substr($css, 0, self::MAX_CSS_LENGTH);
		}
		// Block HTML/script injection in style blocks.
		$css = \str_replace(['</', '<'], '', $css);

		return $css;
	}

	/**
	 * Optional lines of `--bec-* : value;` or full selectors; wrapped for predictable cascade.
	 */
	public static function getScopedThemeVariablesCss(): string
	{
		$inner = self::sanitizeCssBlock(\trim(self::getThemeVariablesCssRaw()));
		if ($inner === '') {
			return '';
		}
		$trim = \ltrim($inner);
		if ($trim !== '' && (
			$trim[0] === '@'
			|| $trim[0] === '.'
			|| $trim[0] === '#'
			|| $trim[0] === ':'
			|| $trim[0] === '['
			|| \strpos($inner, '{') !== false
		)) {
			return $inner;
		}

		return '.bec-search-form-wrap,.bec-booking-summary{' . $inner . '}';
	}

	public static function getSearchExtraCss(): string
	{
		return self::sanitizeCssBlock(self::getSearchExtraCssRaw());
	}

	public static function getSummaryExtraCss(): string
	{
		return self::sanitizeCssBlock(self::getSummaryExtraCssRaw());
	}

	public static function buildInlineCss(): string
	{
		$chunks = [];

		$vars = self::getScopedThemeVariablesCss();
		if ($vars !== '') {
			$chunks[] = $vars;
		}

		$s = self::getSearchExtraCss();
		if ($s !== '') {
			$chunks[] = $s;
		}

		$b = self::getSummaryExtraCss();
		if ($b !== '') {
			$chunks[] = $b;
		}

		return \trim(\implode("\n", \array_filter($chunks, static fn ( string $x ): bool => $x !== '' )));
	}
}
