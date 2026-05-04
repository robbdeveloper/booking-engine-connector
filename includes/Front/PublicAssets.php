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

		$inlineCss = StylingSettings::buildInlineCss();
		if ($inlineCss !== '') {
			\wp_add_inline_style('bec-public', $inlineCss);
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
			[],
			\BEC_VERSION,
			true
		);

		$ctx = SearchContext::fromRequest();

		$l10n = [
			'strAdultsOne'     => \__('%d adult', 'booking-engine-connector'),
			'strAdultsMany'    => \__('%d adults', 'booking-engine-connector'),
			'strWithChildren'  => \__('%1$d adults · %2$d children', 'booking-engine-connector'),
			'strGuestsOne'     => \__('%d guest', 'booking-engine-connector'),
			'strGuestsMany'    => \__('%d guests', 'booking-engine-connector'),
			'strChildAgeLabel'  => \__(
				'Child %d age',
				'booking-engine-connector'
			),
			'strChildAgePlaceholder' => \__(
				'Age',
				'booking-engine-connector'
			),
			'momentLocale'     => self::momentLocaleString(),
			'firstDayOfWeek'   => (int) \get_option('start_of_week', 0),
			'maxNights'        => (int) \apply_filters('bec_search_max_nights', 365, $ctx),
			'minDateToday'     => (bool) \apply_filters('bec_daterangepicker_min_date_today', true, $ctx),
			'maxDateFromToday' => (int) \apply_filters('bec_daterangepicker_max_date_from_today', 730, $ctx),
			'applyLabel'       => \__('Apply', 'booking-engine-connector'),
			'cancelLabel'      => \__('Cancel', 'booking-engine-connector'),
			'checkinLabel'     => \__('Check-in', 'booking-engine-connector'),
			'checkoutLabel'    => \__('Check-out', 'booking-engine-connector'),
			'datePlaceholder'  => '—',
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

		if (StylingSettings::getBookingSummaryPreset() === StylingSettings::BOOKING_SUMMARY_PRESET_COMPACT) {
			\wp_enqueue_style(
				'bec-booking-summary-compact',
				\BEC_PLUGIN_URL . 'assets/styling/booking-summary-compact.css',
				['bec-public', $bsDefaultHandle],
				\BEC_VERSION
			);
		}
	}

	private static function momentLocaleString(): string
	{
		$wp = \get_locale();
		$map  = [
			'en_US' => 'en',
			'en_GB' => 'en-gb',
			'pt_BR' => 'pt-br',
			'zh_CN' => 'zh-cn',
			'zh_TW' => 'zh-tw',
		];
		if (isset($map[$wp])) {
			return $map[$wp];
		}

		return \substr($wp, 0, 2);
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
			if ($post instanceof \WP_Post && (
				\has_shortcode($post->post_content, 'bec_search')
				|| \has_shortcode($post->post_content, 'bec_booking_summary')
			)) {
				return true;
			}
		}

		return (bool) \apply_filters('bec_enqueue_public_assets', false);
	}
}
