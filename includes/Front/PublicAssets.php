<?php

declare(strict_types=1);

namespace BookingEngineConnector\Front;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Styling\StylingSettings;

/**
 * Enqueues public base CSS (`public.css`), per-preset bundles under `assets/styling/`,
 * vendor daterangepicker (enhanced layout only), and scripts.
 */
final class PublicAssets
{
	/**
	 * Shortcodes whose output expects {@see enqueue()} CSS/JS on the front.
	 * Excludes `bec_version` (plain text) and `bec_unit_url` (bare URL for attributes).
	 */
	private const SHORTCODES_NEEDING_PUBLIC_ASSETS = [
		'bec_search',
		'bec_booking_summary',
		'bec_dates',
		'bec_checkout',
		'bec_quote',
		'bec_fallback',
		'bec_unit_info',
	];

	public static function register(): void
	{
		\add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
	}

	public static function enqueue(): void
	{
		if (! self::shouldLoad()) {
			return;
		}

		\wp_enqueue_style(
			'bec-public',
			\BEC_PLUGIN_URL . 'assets/public.css',
			[],
			\BEC_VERSION
		);

		$defaultTokens = StylingSettings::buildDefaultRootVariablesCss();
		if ($defaultTokens !== '') {
			\wp_add_inline_style('bec-public', $defaultTokens);
		}

		self::enqueuePresetStyles();

		\wp_register_script(
			'bec-moment',
			\BEC_PLUGIN_URL . 'assets/vendor/moment-with-locales.min.js',
			[],
			'2.29.4',
			true
		);
		\wp_register_script(
			'bec-daterangepicker',
			\BEC_PLUGIN_URL . 'assets/vendor/daterangepicker.min.js',
			['jquery', 'bec-moment'],
			'3.1.0',
			true
		);

		\wp_enqueue_script(
			'bec-public-search',
			\BEC_PLUGIN_URL . 'assets/public-search.js',
			[],
			\BEC_VERSION,
			true
		);

		\wp_enqueue_script(
			'bec-public-search-daterange',
			\BEC_PLUGIN_URL . 'assets/public-search-daterange.js',
			['jquery', 'bec-moment', 'bec-daterangepicker', 'bec-public-search'],
			\BEC_VERSION,
			true
		);

		\wp_enqueue_script(
			'bec-public-booking-summary',
			\BEC_PLUGIN_URL . 'assets/public-booking-summary.js',
			[ 'bec-public-search-daterange' ],
			\BEC_VERSION,
			true
		);

		$ctx = SearchContext::fromRequest();

		$l10n = [
			/* translators: %d number of adults when only adults are counted (singular) */
			'strAdultsOne'     => \__('%d adult', 'booking-engine-connector'),
			/* translators: %d number of adults when only adults are counted (plural) */
			'strAdultsMany'    => \__('%d adults', 'booking-engine-connector'),
			/* translators: 1: adults count, 2: children count */
			'strWithChildren'  => \__('%1$d adults · %2$d children', 'booking-engine-connector'),
			/* translators: %d total guest count (singular) */
			'strGuestsOne'     => \__('%d guest', 'booking-engine-connector'),
			/* translators: %d total guest count (plural) */
			'strGuestsMany'    => \__('%d guests', 'booking-engine-connector'),
			/* translators: %d child index (display order starts at 1) */
			'strChildAgeLabel' => \__('Child %d age', 'booking-engine-connector'),
			'strChildAgePlaceholder' => \__(
				'Age',
				'booking-engine-connector'
			),
			'momentLocale'     => self::momentLocaleString($ctx),
			'firstDayOfWeek'   => (int) \get_option('start_of_week', 0),
			'maxNights'        => (int) \apply_filters('bec_search_max_nights', 365, $ctx),
			'minDateToday'     => (bool) \apply_filters('bec_daterangepicker_min_date_today', true, $ctx),
			'maxDateFromToday' => (int) \apply_filters('bec_daterangepicker_max_date_from_today', 730, $ctx),
			'applyLabel'       => \__('Apply', 'booking-engine-connector'),
			'cancelLabel'      => \__('Cancel', 'booking-engine-connector'),
			'checkinLabel'     => \__('Check-in', 'booking-engine-connector'),
			'checkoutLabel'    => \__('Check-out', 'booking-engine-connector'),
			/* translators: Label between displayed start/end dates where a range picker shows a textual range. Often an en dash with spaces. */
			'dateRangeSeparator' => \__(' – ', 'booking-engine-connector'),
			'customRangeLabel' => \__('Custom', 'booking-engine-connector'),
			/* translators: Shown where check-in/out dates are not yet chosen (datepicker readout); use a short dash or “Select dates” style label. */
			'datePlaceholder'  => \__('—', 'booking-engine-connector'),
		];

		/**
		 * @var array<string, mixed> $l10n
		 */
		$l10n = \apply_filters('bec_search_form_js_l10n', $l10n, $ctx);

		\wp_localize_script('bec-public-search', 'becSearchForm', $l10n);
	}

	/**
	 * Preset bundles: assets/styling/search-form-{preset}.css, booking-summary-*.css
	 */
	private static function enqueuePresetStyles(): void
	{
		$searchSlug = StylingSettings::getSearchPreset();
		$searchHandle = 'bec-search-form-' . $searchSlug;
		$searchUrl    = \BEC_PLUGIN_URL . 'assets/styling/search-form-' . $searchSlug . '.css';

		if ($searchSlug === StylingSettings::SEARCH_PRESET_ENHANCED) {
			\wp_enqueue_style(
				'bec-daterangepicker',
				\BEC_PLUGIN_URL . 'assets/vendor/daterangepicker.css',
				['bec-public'],
				'3.1.0'
			);
			\wp_enqueue_style($searchHandle, $searchUrl, ['bec-public', 'bec-daterangepicker'], \BEC_VERSION);
		} else {
			\wp_enqueue_style($searchHandle, $searchUrl, ['bec-public'], \BEC_VERSION);
		}

		$bsDefaultHandle = 'bec-booking-summary-default';
		\wp_enqueue_style(
			$bsDefaultHandle,
			\BEC_PLUGIN_URL . 'assets/styling/booking-summary-default.css',
			['bec-public'],
			\BEC_VERSION
		);

		$overrideStyleDeps = [
			'bec-public',
			$searchHandle,
			$bsDefaultHandle,
		];

		if (StylingSettings::getBookingSummaryPreset() === StylingSettings::BOOKING_SUMMARY_PRESET_COMPACT) {
			\wp_enqueue_style(
				'bec-booking-summary-compact',
				\BEC_PLUGIN_URL . 'assets/styling/booking-summary-compact.css',
				['bec-public', $bsDefaultHandle],
				\BEC_VERSION
			);
			$overrideStyleDeps[] = 'bec-booking-summary-compact';
		}

		$lateCss = StylingSettings::buildLateOverrideCss();
		if ($lateCss !== '') {
			\wp_register_style(
				'bec-styling-overrides',
				false,
				$overrideStyleDeps,
				\BEC_VERSION
			);
			\wp_enqueue_style('bec-styling-overrides');
			\wp_add_inline_style('bec-styling-overrides', $lateCss);
		}
	}

	private static function momentLocaleString(SearchContext $ctx): string
	{
		$wpLocale = function_exists('determine_locale')
			? \determine_locale()
			: \get_locale();
		if ($wpLocale === '' || ! \is_string($wpLocale)) {
			$wpLocale = 'en_US';
		}

		$map = [
			'en_US' => 'en',
			'en_GB' => 'en-gb',
			'en_AU' => 'en-au',
			'pt_PT' => 'pt',
			'pt_BR' => 'pt-br',
			'zh_CN' => 'zh-cn',
			'zh_TW' => 'zh-tw',
			'it_IT' => 'it',
			'fr_FR' => 'fr',
			'fr_CA' => 'fr-ca',
			'de_DE' => 'de',
			'de_AT' => 'de-at',
			'es_ES' => 'es',
			'es_MX' => 'es-mx',
			'nl_NL' => 'nl',
			'sv_SE' => 'sv',
			'nb_NO' => 'nb',
			'nn_NO' => 'nn',
			'da_DK' => 'da',
			'fi'    => 'fi',
			'pl_PL' => 'pl',
			'ru_RU' => 'ru',
			'uk'    => 'uk',
			'ja'    => 'ja',
			'ko_KR' => 'ko',
		];

		if (isset($map[ $wpLocale ])) {
			$locale = $map[ $wpLocale ];
		} else {
			$locale = \substr($wpLocale, 0, 2);
		}

		/**
		 * Moment.js locale slug passed to booking UI scripts (`moment.locale`).
		 *
		 * @param string                                   $momentLocale Mapped slug (e.g. `it` for Italian).
		 * @param string                                   $wpLocale     WordPress locale (e.g. `it_IT`).
		 * @param array<string, string>                    $map          Locale map used before this filter runs.
		 * @param \BookingEngineConnector\Search\SearchContext $ctx Search context passed to localize.
		 */
		return (string) \apply_filters('bec_moment_locale', $locale, $wpLocale, $map, $ctx);
	}

	private static function shouldLoad(): bool
	{
		if (\is_singular(UnitPostType::getSlug())) {
			return true;
		}
		if (\is_post_type_archive(UnitPostType::getSlug())) {
			return true;
		}

		if (\is_singular()) {
			$post = \get_post();
			if ($post instanceof \WP_Post && self::storedContentNeedsPublicAssets($post->post_content)) {
				return true;
			}
		}

		if (self::widgetOptionContentNeedsPublicAssets()) {
			return true;
		}

		return (bool) \apply_filters('bec_enqueue_public_assets', false);
	}

	/**
	 * @return list<string>
	 */
	private static function shortcodesRequiringPublicAssets(): array
	{
		$tags = \apply_filters('bec_shortcodes_requiring_public_assets', self::SHORTCODES_NEEDING_PUBLIC_ASSETS);
		if (! \is_array($tags)) {
			return self::SHORTCODES_NEEDING_PUBLIC_ASSETS;
		}

		$out = [];
		foreach ($tags as $t) {
			$t = \trim((string) $t);
			if ($t !== '') {
				$out[] = $t;
			}
		}

		return $out !== [] ? \array_values(\array_unique($out)) : self::SHORTCODES_NEEDING_PUBLIC_ASSETS;
	}

	private static function stringContainsTrackedShortcode(string $haystack): bool
	{
		if ($haystack === '') {
			return false;
		}
		foreach (self::shortcodesRequiringPublicAssets() as $tag) {
			if (\has_shortcode($haystack, $tag)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect BEC shortcodes in stored content, including block editor reusable blocks (`core/block` ref).
	 *
	 * @param array<int> $visitedReusableIds Post IDs already followed for synched/reusable blocks (cycle guard).
	 */
	private static function storedContentNeedsPublicAssets(string $content, array $visitedReusableIds = []): bool
	{
		if (self::stringContainsTrackedShortcode($content)) {
			return true;
		}

		if (! \function_exists('has_blocks') || ! \function_exists('parse_blocks') || ! \has_blocks($content)) {
			return false;
		}

		return self::blocksTreeContainsTrackedShortcode(\parse_blocks($content), $visitedReusableIds);
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int>                       $visitedReusableIds
	 */
	private static function blocksTreeContainsTrackedShortcode(array $blocks, array $visitedReusableIds): bool
	{
		foreach ($blocks as $block) {
			if (! \is_array($block)) {
				continue;
			}

			if (($block['blockName'] ?? null) === 'core/block' && isset($block['attrs']) && \is_array($block['attrs'])) {
				$ref = isset($block['attrs']['ref']) ? (int) $block['attrs']['ref'] : 0;
				if ($ref > 0 && ! \in_array($ref, $visitedReusableIds, true)) {
					$nextVisited   = $visitedReusableIds;
					$nextVisited[] = $ref;
					$refPost       = \get_post($ref);
					if ($refPost instanceof \WP_Post && $refPost->post_status === 'publish' && $refPost->post_content !== '') {
						if (self::storedContentNeedsPublicAssets((string) $refPost->post_content, $nextVisited)) {
							return true;
						}
					}
				}
			}

			$inner = $block['innerHTML'] ?? '';
			if (\is_string($inner) && $inner !== '' && self::stringContainsTrackedShortcode($inner)) {
				return true;
			}

			$innerChunks = $block['innerContent'] ?? null;
			if (\is_array($innerChunks)) {
				foreach ($innerChunks as $chunk) {
					if (\is_string($chunk) && $chunk !== '' && self::stringContainsTrackedShortcode($chunk)) {
						return true;
					}
				}
			}

			$innerBlocks = $block['innerBlocks'] ?? null;
			if (\is_array($innerBlocks) && $innerBlocks !== [] && self::blocksTreeContainsTrackedShortcode($innerBlocks, $visitedReusableIds)) {
				return true;
			}
		}

		return false;
	}

	private static function widgetOptionContentNeedsPublicAssets(): bool
	{
		foreach (['widget_block', 'widget_text', 'widget_custom_html'] as $option) {
			$raw = \get_option($option, null);
			if (! \is_array($raw)) {
				continue;
			}
			foreach ($raw as $key => $instance) {
				if ($key === '_multiwidget' || ! \is_array($instance)) {
					continue;
				}
				$pieces = [];
				if (isset($instance['content']) && \is_string($instance['content'])) {
					$pieces[] = $instance['content'];
				}
				if (isset($instance['text']) && \is_string($instance['text'])) {
					$pieces[] = $instance['text'];
				}
				foreach ($pieces as $chunk) {
					if ($chunk !== '' && self::storedContentNeedsPublicAssets($chunk)) {
						return true;
					}
				}
			}
		}

		return false;
	}
}
