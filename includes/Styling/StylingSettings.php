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

	/**
	 * Theme variables shown in admin. Prefills plugin defaults when the option is empty.
	 */
	public static function getThemeVariablesCssForAdmin(): string
	{
		$raw = \trim(self::getThemeVariablesCssRaw());

		return $raw === '' ? self::getDefaultThemeVariablesInner() : self::getThemeVariablesCssRaw();
	}

	/**
	 * Inner declaration list for :root (no braces). Edit via Styling admin or filters.
	 */
	public static function getDefaultThemeVariablesInner(): string
	{
		$inner = <<<'CSS'
/* Fonts — load via theme/Elementor or add @import in “Extra CSS”; stack only here */
--bec-font-sans: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
--bec-font-size-sm: 0.8125rem;
--bec-font-size-md: 0.9375rem;
--bec-font-size-lg: 1rem;
--bec-font-weight-label: 700;
--bec-font-weight-value: 400;

/* Core palette */
--bec-color-bg: #ffffff;
--bec-color-surface: #f9fafb;
--bec-color-text: #3d3d3d;
--bec-color-text-muted: #6b7280;
--bec-color-border: #e0e0e0;
--bec-color-border-strong: #d1d5db;
--bec-color-accent: #000000;
--bec-color-accent-text: #ffffff;
--bec-icon-tint: #000000;
--bec-color-error: #b91c1c;
--bec-color-error-muted: #b32d2e;
--bec-color-muted-soft: #9ca3af;
--bec-focus-ring-outer: #ffffff;

/* Shape */
--bec-border-width: 1px;
--bec-radius-field: 1.125rem;
--bec-radius-popover: 0.75rem;
--bec-radius-pill: 999px;
--bec-radius-ui: 8px;
--bec-radius-readout: 0.5rem;
--bec-radius-input: 4px;

/* Elevation */
--bec-shadow-bar: 0 2px 10px rgba(0, 0, 0, 0.06);
--bec-shadow-popover: 0 10px 40px rgba(0, 0, 0, 0.12);
--bec-backdrop: rgba(17, 24, 39, 0.45);
--bec-shadow-panel-raised: 0 -8px 32px rgba(0, 0, 0, 0.15);

/* Motion + fields */
--bec-transition: 0.2s ease;
--bec-field-padding-y: 0.65rem;
--bec-field-padding-x: 0.9rem;

/* Date range picker (portal; lives under body — keep on :root) */
--bec-drp-z-index: 10050;
--bec-drp-range-bg: #c1c1c1;
--bec-drp-range-edge: #222222;
--bec-drp-range-ends: #000000;
--bec-drp-range-edge-text: #ffffff;
--bec-drp-day-muted: #ebebeb;
--bec-drp-day-header: #717171;
--bec-drp-border: #e5e7eb;
--bec-drp-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
--bec-drp-arrow-border: #c4c4c4;

/* Inline icons (override stroke via --bec-icon-tint if you regenerate SVG data URLs) */
--bec-cal-icon: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23000000' stroke-width='1.8'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'/%3E%3C/svg%3E");
--bec-users-icon: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23000000' stroke-width='1.8'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z'/%3E%3C/svg%3E");
--bec-search-icon: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23ffffff' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M21 21l-4.35-4.35m0 0A7.5 7.5 0 103.5 3.5a7.5 7.5 0 0013.15 13.15z'/%3E%3C/svg%3E");

/* Booking summary (aliases; override in plain variables or Extra CSS) */
--bec-bsummary-bg: #fafafa;
--bec-bsummary-border: #ebebeb;
--bec-bsummary-radius: 0.5rem;
--bec-bsummary-color-text: #374151;
--bec-bsummary-color-muted: #6b7280;
--bec-bsummary-color-accent: #505050;
--bec-bsummary-color-primary: #000000;
--bec-bsummary-font: var(--bec-font-sans);
--bec-bsummary-sh-bar: 0 -4px 20px rgba(0, 0, 0, 0.08);
--bec-bsummary-date-divider: rgba(107, 91, 69, 0.14);
--bec-bsummary-loading-overlay: rgba(250, 248, 245, 0.72);
--bec-bsummary-spinner-border: rgba(92, 77, 58, 0.2);
--bec-bsummary-spinner-border-top: var(--bec-bsummary-color-accent);
--bec-bsummary-spinner-border-dim: rgba(92, 77, 58, 0.45);

/* Shortcode utilities (classic search, fallback) */
--bec-fallback-border: #dddddd;
--bec-fallback-bg: #fafafa;
--bec-fallback-radius: 2px;
--bec-status-ok: #1e4620;
--bec-status-muted: #666666;
CSS;

		/**
		 * @var string $inner
		 */
		$inner = \apply_filters('bec_styling_default_theme_variables_inner', $inner);

		return \trim($inner);
	}

	/**
	 * Early frontend: plugin default tokens on :root (before preset CSS).
	 */
	public static function buildDefaultRootVariablesCss(): string
	{
		$inner = self::sanitizeCssBlock(self::getDefaultThemeVariablesInner());
		if ($inner === '') {
			return '';
		}

		return ':root{' . $inner . '}';
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
	 * User “Shared theme variables”: plain custom properties or full rules.
	 */
	public static function getScopedUserThemeVariablesCss(): string
	{
		return self::scopePlainPropertyBlockOrRules(self::getThemeVariablesCssRaw());
	}

	/**
	 * @internal Used for user-defined theme variables only (not merged defaults).
	 */
	private static function scopePlainPropertyBlockOrRules(string $raw): string
	{
		$inner = self::sanitizeCssBlock(\trim($raw));
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

	/**
	 * Optional lines of `--bec-* : value;` or full selectors; wrapped for predictable cascade.
	 *
	 * @deprecated Use {@see getScopedUserThemeVariablesCss()} with {@see buildLateOverrideCss()}.
	 */
	public static function getScopedThemeVariablesCss(): string
	{
		return self::getScopedUserThemeVariablesCss();
	}

	public static function getSearchExtraCss(): string
	{
		return self::sanitizeCssBlock(self::getSearchExtraCssRaw());
	}

	public static function getSummaryExtraCss(): string
	{
		return self::sanitizeCssBlock(self::getSummaryExtraCssRaw());
	}

	/**
	 * CSS appended after all preset styles so admin overrides win.
	 */
	public static function buildLateOverrideCss(): string
	{
		$chunks = [];

		$vars = self::getScopedUserThemeVariablesCss();
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

	/**
	 * @deprecated Use {@see buildDefaultRootVariablesCss()} plus {@see buildLateOverrideCss()}.
	 */
	public static function buildInlineCss(): string
	{
		$chunks = [];

		$vars = self::getScopedUserThemeVariablesCss();
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
