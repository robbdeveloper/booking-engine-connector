<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin\Settings;

use BookingEngineConnector\Admin\AdminMenu;
use BookingEngineConnector\Admin\AdminPageLayout;
use BookingEngineConnector\Front\PublicContentSettings;
use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\Search\SearchSettings;

/**
 * Front-end search and single-unit content injection settings.
 */
final class FrontendPage
{
	public const PAGE_SLUG = 'bec-frontend';

	private const NONCE_ACTION = 'bec_frontend_save';

	public static function register(): void
	{
		\add_action('admin_init', [self::class, 'handlePost']);
	}

	public static function render(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$guestInputMode = SearchSettings::getGuestInputModeOption();
		$childAgesMode  = SearchSettings::getChildAgesModeOption();
		$autoSearchForm = SearchSettings::isAutoAppendSearchFormOnSingleUnit();
		$appendBooking  = PublicContentSettings::isAppendBookingBlocksToContentEnabled();
		$syncTranslations = MultilingualBridge::isFeatureEnabled();

		AdminPageLayout::wrapOpen(
			\__('Frontend', 'booking-engine-connector'),
			\__(
				'Configure how the availability search bar collects guests and how booking blocks appear on single unit pages.',
				'booking-engine-connector'
			),
			'bec-frontend'
		);

		self::renderNotices();

		echo '<form method="post" action="' . \esc_url(\admin_url('admin.php')) . '">';
		echo '<input type="hidden" name="page" value="' . \esc_attr(self::PAGE_SLUG) . '" />';
		\wp_nonce_field(self::NONCE_ACTION, 'bec_frontend_nonce');

		AdminPageLayout::cardOpen(
			\__('Search form', 'booking-engine-connector'),
			\__(
				'Controls the `[bec_search]` shortcode and embedded search inside `[bec_booking_summary]`.',
				'booking-engine-connector'
			)
		);

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="bec_search_guest_input_mode">' . \esc_html__('How guests are collected', 'booking-engine-connector') . '</label></th><td>';
		echo '<select name="bec_search_guest_input_mode" id="bec_search_guest_input_mode">';
		echo '<option value="' . \esc_attr(SearchSettings::GUEST_MODE_PROVIDER) . '" ' . \selected($guestInputMode, SearchSettings::GUEST_MODE_PROVIDER, false) . '>' . \esc_html__(
			'Follow the active provider (default)',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(SearchSettings::GUEST_MODE_TOTAL) . '" ' . \selected($guestInputMode, SearchSettings::GUEST_MODE_TOTAL, false) . '>' . \esc_html__(
			'Single “Guests” count only',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(SearchSettings::GUEST_MODE_BREAKDOWN) . '" ' . \selected($guestInputMode, SearchSettings::GUEST_MODE_BREAKDOWN, false) . '>' . \esc_html__(
			'Adults and children (separate fields)',
			'booking-engine-connector'
		) . '</option>';
		echo '</select>';
		echo '<p class="description">' . \esc_html__(
			'Controls the availability search bar. Use a single total or split adults/children, regardless of the provider. “Follow the active provider” uses each engine’s own rules (e.g. Kross can default to a simple guest count).',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bec_search_child_ages_mode">' . \esc_html__('Child ages in search', 'booking-engine-connector') . '</label></th><td>';
		echo '<select name="bec_search_child_ages_mode" id="bec_search_child_ages_mode">';
		echo '<option value="' . \esc_attr(SearchSettings::CHILD_AGES_PROVIDER) . '" ' . \selected($childAgesMode, SearchSettings::CHILD_AGES_PROVIDER, false) . '>' . \esc_html__(
			'Follow the active provider',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(SearchSettings::CHILD_AGES_YES) . '" ' . \selected($childAgesMode, SearchSettings::CHILD_AGES_YES, false) . '>' . \esc_html__(
			'Ask for each child’s age',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(SearchSettings::CHILD_AGES_NO) . '" ' . \selected($childAgesMode, SearchSettings::CHILD_AGES_NO, false) . '>' . \esc_html__(
			'Do not ask for child ages',
			'booking-engine-connector'
		) . '</option>';
		echo '</select>';
		echo '<p class="description">' . \esc_html__(
			'Applies when the search form shows adults and children. Ignored when using only a single guest count.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		AdminPageLayout::cardClose();

		AdminPageLayout::cardOpen(
			\__('Single unit pages', 'booking-engine-connector'),
			\__(
				'Automatic content injection when viewing a synced unit. Disable these to place shortcodes manually in your template or block editor.',
				'booking-engine-connector'
			)
		);

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . \esc_html__('Content injection', 'booking-engine-connector') . '</th><td>';
		echo '<fieldset>';
		echo '<label><input type="checkbox" name="bec_auto_append_search_form_single_unit" value="1" ' . \checked($autoSearchForm, true, false) . ' /> ';
		echo \esc_html__(
			'Insert the availability search form above the main post content',
			'booking-engine-connector'
		) . '</label>';
		echo '<p class="description">' . \esc_html__(
			'When disabled, place `[bec_search]` (or the booking summary shortcode) in your template or block editor instead.',
			'booking-engine-connector'
		) . '</p>';
		echo '<label><input type="checkbox" name="bec_append_booking_blocks_to_content" value="1" ' . \checked($appendBooking, true, false) . ' /> ';
		echo \esc_html__(
			'Append the booking quote and Continue button after the main post content when the URL has dates',
			'booking-engine-connector'
		) . '</label>';
		echo '<p class="description">' . \esc_html__(
			'When disabled, use `[bec_booking_summary]` or `[bec_quote]` where you want pricing and checkout.',
			'booking-engine-connector'
		) . '</p>';
		echo '</fieldset>';
		echo '</td></tr>';
		echo '</table>';
		AdminPageLayout::cardClose();

		if (MultilingualBridge::isActive()) {
			AdminPageLayout::cardOpen(
				\__('Multilingual', 'booking-engine-connector'),
				\__(
					'When WPML or Polylang is active, synced units can automatically receive linked translation posts from provider locale maps.',
					'booking-engine-connector'
				)
			);

			echo '<table class="form-table" role="presentation">';
			echo '<tr><th scope="row">' . \esc_html__('Translation sync', 'booking-engine-connector') . '</th><td>';
			echo '<fieldset>';
			echo '<label><input type="checkbox" name="bec_sync_translations_enabled" value="1" ' . \checked($syncTranslations, true, false) . ' /> ';
			echo \esc_html__(
				'Create and update linked translation posts on each unit sync',
				'booking-engine-connector'
			) . '</label>';
			echo '<p class="description">' . \esc_html__(
				'Uses Kross be_name and be_description locale maps when available. Title, description, and their core meta fields are language-specific; images, amenities, categories, and sync payload stay shared.',
				'booking-engine-connector'
			) . '</p>';
			echo '</fieldset>';
			echo '</td></tr>';
			echo '</table>';
			AdminPageLayout::cardClose();
		}

		echo '<p class="submit"><button type="submit" class="button button-primary">' . \esc_html__(
			'Save frontend settings',
			'booking-engine-connector'
		) . '</button></p>';
		echo '</form>';

		AdminPageLayout::wrapClose();
	}

	public static function handlePost(): void
	{
		if (! isset($_POST['page'], $_POST['bec_frontend_nonce'])) {
			return;
		}

		if (\sanitize_key(\wp_unslash((string) $_POST['page'])) !== self::PAGE_SLUG) {
			return;
		}

		$nonce = \wp_unslash((string) $_POST['bec_frontend_nonce']);
		if (! \wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$gMode = isset($_POST['bec_search_guest_input_mode'])
			? \sanitize_key(\wp_unslash((string) $_POST['bec_search_guest_input_mode']))
			: SearchSettings::GUEST_MODE_PROVIDER;
		if (! \in_array($gMode, [SearchSettings::GUEST_MODE_PROVIDER, SearchSettings::GUEST_MODE_TOTAL, SearchSettings::GUEST_MODE_BREAKDOWN], true)) {
			$gMode = SearchSettings::GUEST_MODE_PROVIDER;
		}
		\update_option(SearchSettings::OPTION_GUEST_INPUT_MODE, $gMode, false);

		$cMode = isset($_POST['bec_search_child_ages_mode'])
			? \sanitize_key(\wp_unslash((string) $_POST['bec_search_child_ages_mode']))
			: SearchSettings::CHILD_AGES_PROVIDER;
		if (! \in_array($cMode, [SearchSettings::CHILD_AGES_PROVIDER, SearchSettings::CHILD_AGES_YES, SearchSettings::CHILD_AGES_NO], true)) {
			$cMode = SearchSettings::CHILD_AGES_PROVIDER;
		}
		\update_option(SearchSettings::OPTION_CHILD_AGES_MODE, $cMode, false);

		\update_option(
			SearchSettings::OPTION_AUTO_APPEND_SEARCH_FORM_SINGLE_UNIT,
			isset($_POST['bec_auto_append_search_form_single_unit']) ? 1 : 0,
			false
		);
		\update_option(
			PublicContentSettings::OPTION_APPEND_BOOKING_BLOCKS_TO_CONTENT,
			isset($_POST['bec_append_booking_blocks_to_content']) ? 1 : 0,
			false
		);

		if (MultilingualBridge::isActive()) {
			\update_option(
				MultilingualBridge::OPTION_SYNC_TRANSLATIONS_ENABLED,
				isset($_POST['bec_sync_translations_enabled']) ? 1 : 0,
				false
			);
		}

		self::setFlash('success', \__('Frontend settings saved.', 'booking-engine-connector'));
		self::redirectBack();
	}

	private static function redirectBack(): void
	{
		$url = \add_query_arg(
			[
				'page'      => self::PAGE_SLUG,
				'bec_flash' => '1',
			],
			\admin_url('admin.php')
		);

		\wp_safe_redirect($url);
		exit;
	}

	private static function setFlash(string $type, string $message): void
	{
		\set_transient(
			self::flashKey(),
			[
				'type'    => $type,
				'message' => $message,
			],
			120
		);
	}

	private static function flashKey(): string
	{
		return 'bec_frontend_flash_' . \get_current_user_id();
	}

	private static function renderNotices(): void
	{
		if (! isset($_GET['bec_flash'])) {
			return;
		}

		$data = \get_transient(self::flashKey());
		\delete_transient(self::flashKey());

		if (! \is_array($data) || ! isset($data['type'], $data['message'])) {
			return;
		}

		$type    = $data['type'] === 'success' ? 'success' : 'error';
		$message = (string) $data['message'];

		echo '<div class="notice notice-' . \esc_attr($type) . ' is-dismissible"><p>' . \esc_html($message) . '</p></div>';
	}
}
