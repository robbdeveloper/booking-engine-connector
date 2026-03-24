<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin\Settings;

use BookingEngineConnector\Admin\AdminMenu;
use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Admin setting for the public URL slug of synced units (rewrite only; internal post type stays bec_unit).
 */
final class UnitPermalinkPage
{
	public const PAGE_SLUG = 'bec-units';

	private const NONCE_ACTION = 'bec_unit_permalink_save';

	public static function register(): void
	{
		\add_action('admin_init', [self::class, 'handlePost']);
	}

	public static function render(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$stored = (string) \get_option(UnitPostType::OPTION_PERMALINK_SLUG, '');
		$hasArchive = (bool) \get_option(UnitPostType::OPTION_HAS_ARCHIVE, false);
		$exampleSlug = UnitPostType::getPermalinkSlug();

		echo '<div class="wrap">';
		if (isset($_GET['bec_saved']) && (string) \sanitize_text_field(\wp_unslash((string) $_GET['bec_saved'])) === '1') {
			echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html__(
				'Settings saved.',
				'booking-engine-connector'
			) . '</p></div>';
		}
		echo '<h1>' . \esc_html__('Units — permalinks', 'booking-engine-connector') . '</h1>';
		echo '<p class="description">' . \esc_html__(
			'Set the URL slug used for unit archives and single-unit URLs. The internal post type name in the database stays unchanged.',
			'booking-engine-connector'
		) . '</p>';

		echo '<form method="post" action="' . \esc_url(\admin_url('admin.php')) . '">';
		echo '<input type="hidden" name="page" value="' . \esc_attr(self::PAGE_SLUG) . '" />';
		\wp_nonce_field(self::NONCE_ACTION, 'bec_unit_permalink_nonce');

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="bec_unit_permalink_slug">' . \esc_html__('URL slug', 'booking-engine-connector') . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="bec_unit_permalink_slug" id="bec_unit_permalink_slug" value="' . \esc_attr($stored) . '" autocomplete="off" placeholder="' . \esc_attr('bec_unit') . '" />';
		$home = \home_url('/');
		$sample = \trailingslashit($home) . $exampleSlug . '/';
		echo '<p class="description">' . \sprintf(
			/* translators: %s: example URL prefix for current slug */
			\esc_html__('With your current settings, URLs begin with %s. Leave the field empty to use the default slug “bec_unit”.', 'booking-engine-connector'),
			'<code>' . \esc_html($sample) . '</code>'
		) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . \esc_html__('Unit archive', 'booking-engine-connector') . '</th><td>';
		echo '<label for="bec_unit_has_archive"><input type="checkbox" name="bec_unit_has_archive" id="bec_unit_has_archive" value="1" ' . \checked($hasArchive, true, false) . ' /> ';
		echo \esc_html__('Enable the public archive page for units (listing URL at the slug above).', 'booking-engine-connector') . '</label>';
		echo '<p class="description">' . \esc_html__(
			'When disabled, only single unit URLs are registered. Changing this updates rewrite rules.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';
		echo '</table>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . \esc_html__('Save changes', 'booking-engine-connector') . '</button></p>';
		echo '</form></div>';
	}

	public static function handlePost(): void
	{
		if (! isset($_POST['page'], $_POST['bec_unit_permalink_nonce']) || (string) \sanitize_key(\wp_unslash((string) $_POST['page'])) !== self::PAGE_SLUG) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		\check_admin_referer(self::NONCE_ACTION, 'bec_unit_permalink_nonce');

		$raw = isset($_POST['bec_unit_permalink_slug']) ? \wp_unslash((string) $_POST['bec_unit_permalink_slug']) : '';
		$raw = trim($raw);
		if ($raw === '') {
			\update_option(UnitPostType::OPTION_PERMALINK_SLUG, '', false);
		} else {
			\update_option(UnitPostType::OPTION_PERMALINK_SLUG, \sanitize_title($raw), false);
		}

		\update_option(UnitPostType::OPTION_HAS_ARCHIVE, isset($_POST['bec_unit_has_archive']), false);

		\flush_rewrite_rules(false);

		\wp_safe_redirect(\add_query_arg(['page' => self::PAGE_SLUG, 'bec_saved' => '1'], \admin_url('admin.php')));
		exit;
	}
}
