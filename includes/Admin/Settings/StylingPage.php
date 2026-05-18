<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin\Settings;

use BookingEngineConnector\Admin\AdminMenu;
use BookingEngineConnector\Styling\StylingSettings;

/**
 * Global shortcode styling: CSS tokens, presets, optional extra CSS.
 */
final class StylingPage
{
	public const PAGE_SLUG = 'bec-styling';

	private const NONCE_ACTION = 'bec_styling_save';

	public static function register(): void
	{
		\add_action('admin_init', [self::class, 'handlePost']);
		\add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
	}

	public static function enqueueAdminAssets(string $hookSuffix): void
	{
		if ($hookSuffix !== 'bec-dashboard_page_' . self::PAGE_SLUG) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		\wp_enqueue_style(
			'bec-admin-styling',
			\BEC_PLUGIN_URL . 'assets/admin-styling.css',
			[],
			\BEC_VERSION
		);

		$settings = \wp_enqueue_code_editor(
			[
				'type'       => 'text/css',
				'codemirror' => [
					'indentUnit' => 2,
					'tabSize'    => 2,
				],
			]
		);

		$scriptDeps = [];
		if (false === $settings) {
			$settings = [ 'disabled' => true ];
		} else {
			$scriptDeps[] = 'wp-code-editor';
		}

		\wp_enqueue_script(
			'bec-admin-styling',
			\BEC_PLUGIN_URL . 'assets/admin-styling.js',
			$scriptDeps,
			\BEC_VERSION,
			true
		);

		\wp_add_inline_script(
			'bec-admin-styling',
			'window.becStylingCodeEditor = ' . \wp_json_encode($settings) . ';',
			'before'
		);
	}

	public static function render(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$sPreset       = StylingSettings::getSearchPreset();
		$bPreset       = StylingSettings::getBookingSummaryPreset();
		$vars          = StylingSettings::getThemeVariablesCssForAdmin();
		$searchExtra   = StylingSettings::getSearchExtraCssRaw();
		$summaryExtra  = StylingSettings::getSummaryExtraCssRaw();
		$accIncl       = StylingSettings::isAccordionInclusionsEnabled();
		$accCond       = StylingSettings::isAccordionConditionsEnabled();

		echo '<div class="wrap bec-styling-admin">';
		if (isset($_GET['bec_saved']) && (string) \sanitize_text_field(\wp_unslash((string) $_GET['bec_saved'])) === '1') {
			echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html__(
				'Settings saved.',
				'booking-engine-connector'
			) . '</p></div>';
		}
		echo '<h1>' . \esc_html__('Styling (shortcodes)', 'booking-engine-connector') . '</h1>';
		echo '<p class="description">' . \esc_html__(
			'Tune the search form and booking summary with a small semantic token block (colors, font stack, radius scale). Detailed layout or vendor overrides (.daterangepicker, etc.) go in Extra CSS below. Preset layout CSS loads first; shared tokens and Extra CSS load last.',
			'booking-engine-connector'
		) . '</p>';

		echo '<form method="post" action="' . \esc_url(\admin_url('admin.php')) . '">';
		echo '<input type="hidden" name="page" value="' . \esc_attr(self::PAGE_SLUG) . '" />';
		\wp_nonce_field(self::NONCE_ACTION, 'bec_styling_nonce');

		echo '<h2>' . \esc_html__('Design system (shared tokens)', 'booking-engine-connector') . '</h2>';
		echo '<p class="description">' . \esc_html__(
			'Edit the semantic tokens (--bec-font-family, --bec-color-*, --bec-radius-*). Plain properties are applied on :root in late CSS so they reach portaled pickers (calendar, guest sheet); they also affect the rest of the site for any other use of the same --bec-* names. Paste a full selector block anytime you need narrower scoping or finer control.',
			'booking-engine-connector'
		) . '</p>';
		echo '<div class="bec-styling-field"><textarea name="bec_styling_theme_variables" id="bec_styling_theme_variables" class="large-text code" rows="14">' . \esc_textarea($vars) . '</textarea></div>';

		echo '<h2>' . \esc_html__('Search bar', 'booking-engine-connector') . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="bec_styling_search_preset">' . \esc_html__('Layout style', 'booking-engine-connector') . '</label></th><td>';
		echo '<select name="bec_styling_search_preset" id="bec_styling_search_preset">';
		echo '<option value="' . \esc_attr(StylingSettings::SEARCH_PRESET_ENHANCED) . '" ' . \selected($sPreset, StylingSettings::SEARCH_PRESET_ENHANCED, false) . '>' . \esc_html__(
			'Enhanced — date range + guest popover',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(StylingSettings::SEARCH_PRESET_CLASSIC) . '" ' . \selected($sPreset, StylingSettings::SEARCH_PRESET_CLASSIC, false) . '>' . \esc_html__(
			'Classic — separate fields',
			'booking-engine-connector'
		) . '</option>';
		echo '</select>';
		echo '<p class="description">' . \esc_html__(
			'Applies to [bec_search] and embedded search inside [bec_booking_summary].',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="bec_styling_search_extra_css">' . \esc_html__('Search — extra CSS', 'booking-engine-connector') . '</label></th><td>';
		echo '<div class="bec-styling-field"><textarea name="bec_styling_search_extra_css" id="bec_styling_search_extra_css" class="large-text code" rows="10">' . \esc_textarea($searchExtra) . '</textarea></div>';
		echo '<p class="description">' . \esc_html__(
			'Optional. Target classes such as .bec-search-form, .bec-search-form-wrap--enhanced, .daterangepicker.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr></table>';

		echo '<h2>' . \esc_html__('Booking summary', 'booking-engine-connector') . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="bec_styling_booking_summary_preset">' . \esc_html__('Layout style', 'booking-engine-connector') . '</label></th><td>';
		echo '<select name="bec_styling_booking_summary_preset" id="bec_styling_booking_summary_preset">';
		echo '<option value="' . \esc_attr(StylingSettings::BOOKING_SUMMARY_PRESET_DEFAULT) . '" ' . \selected($bPreset, StylingSettings::BOOKING_SUMMARY_PRESET_DEFAULT, false) . '>' . \esc_html__(
			'Default — search panel above summary content',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(StylingSettings::BOOKING_SUMMARY_PRESET_COMPACT) . '" ' . \selected($bPreset, StylingSettings::BOOKING_SUMMARY_PRESET_COMPACT, false) . '>' . \esc_html__(
			'Compact — search panel after summary blocks (desktop)',
			'booking-engine-connector'
		) . '</option>';
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . \esc_html__('Rate details accordions', 'booking-engine-connector') . '</th><td>';
		echo '<label><input type="checkbox" name="bec_styling_accordion_inclusions" value="1" ' . \checked($accIncl, true, false) . ' /> ';
		echo \esc_html__('Show the inclusions accordion when the rate provides text.', 'booking-engine-connector') . '</label><br />';
		echo '<label><input type="checkbox" name="bec_styling_accordion_conditions" value="1" ' . \checked($accCond, true, false) . ' /> ';
		echo \esc_html__('Show the conditions accordion when the rate provides text.', 'booking-engine-connector') . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bec_styling_summary_extra_css">' . \esc_html__('Booking summary — extra CSS', 'booking-engine-connector') . '</label></th><td>';
		echo '<div class="bec-styling-field"><textarea name="bec_styling_summary_extra_css" id="bec_styling_summary_extra_css" class="large-text code" rows="10">' . \esc_textarea($summaryExtra) . '</textarea></div>';
		echo '<p class="description">' . \esc_html__(
			'Optional. Target .bec-booking-summary and inner BEM classes.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr></table>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . \esc_html__('Save changes', 'booking-engine-connector') . '</button></p>';
		echo '</form></div>';
	}

	public static function handlePost(): void
	{
		if (! isset($_POST['page'], $_POST['bec_styling_nonce']) || (string) \sanitize_key(\wp_unslash((string) $_POST['page'])) !== self::PAGE_SLUG) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		\check_admin_referer(self::NONCE_ACTION, 'bec_styling_nonce');

		$sp = isset($_POST['bec_styling_search_preset']) ? \sanitize_key(\wp_unslash((string) $_POST['bec_styling_search_preset'])) : StylingSettings::SEARCH_PRESET_ENHANCED;
		if (! \in_array($sp, [StylingSettings::SEARCH_PRESET_ENHANCED, StylingSettings::SEARCH_PRESET_CLASSIC], true)) {
			$sp = StylingSettings::SEARCH_PRESET_ENHANCED;
		}
		\update_option(StylingSettings::OPTION_SEARCH_PRESET, $sp, false);

		$bp = isset($_POST['bec_styling_booking_summary_preset']) ? \sanitize_key(\wp_unslash((string) $_POST['bec_styling_booking_summary_preset'])) : StylingSettings::BOOKING_SUMMARY_PRESET_DEFAULT;
		if (! \in_array($bp, [StylingSettings::BOOKING_SUMMARY_PRESET_DEFAULT, StylingSettings::BOOKING_SUMMARY_PRESET_COMPACT], true)) {
			$bp = StylingSettings::BOOKING_SUMMARY_PRESET_DEFAULT;
		}
		\update_option(StylingSettings::OPTION_BOOKING_SUMMARY_PRESET, $bp, false);

		\update_option(StylingSettings::OPTION_ACCORDION_INCLUSIONS, isset($_POST['bec_styling_accordion_inclusions']), false);
		\update_option(StylingSettings::OPTION_ACCORDION_CONDITIONS, isset($_POST['bec_styling_accordion_conditions']), false);

		$vars = isset($_POST['bec_styling_theme_variables']) ? \wp_unslash((string) $_POST['bec_styling_theme_variables']) : '';
		\update_option(StylingSettings::OPTION_THEME_VARIABLES_CSS, StylingSettings::sanitizeCssBlock($vars), false);

		$se = isset($_POST['bec_styling_search_extra_css']) ? \wp_unslash((string) $_POST['bec_styling_search_extra_css']) : '';
		\update_option(StylingSettings::OPTION_SEARCH_EXTRA_CSS, StylingSettings::sanitizeCssBlock($se), false);

		$be = isset($_POST['bec_styling_summary_extra_css']) ? \wp_unslash((string) $_POST['bec_styling_summary_extra_css']) : '';
		\update_option(StylingSettings::OPTION_SUMMARY_EXTRA_CSS, StylingSettings::sanitizeCssBlock($be), false);

		\wp_safe_redirect(\add_query_arg(['page' => self::PAGE_SLUG, 'bec_saved' => '1'], \admin_url('admin.php')));
		exit;
	}
}
