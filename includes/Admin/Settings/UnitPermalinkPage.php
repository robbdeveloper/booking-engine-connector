<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin\Settings;

use BookingEngineConnector\Admin\AdminMenu;
use BookingEngineConnector\Admin\AdminPageLayout;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Routing\UnitPermalinkSettings;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

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

		$categoryEnabled = (bool) \get_option(UnitCategoryTaxonomy::OPTION_ENABLED, false);
		$categorySlugStored = (string) \get_option(UnitCategoryTaxonomy::OPTION_PERMALINK_SLUG, '');
		$categoryExampleSlug = UnitCategoryTaxonomy::resolvePermalinkSlug();

		$unitStructure = UnitPermalinkSettings::resolveUnitStructure();
		$categoryStructure = UnitPermalinkSettings::resolveCategoryStructure();
		$examples = UnitPermalinkSettings::exampleUrls(
			$unitStructure,
			$categoryStructure,
			$exampleSlug,
			$categoryExampleSlug
		);

		AdminPageLayout::wrapOpen(
			\__('Units', 'booking-engine-connector'),
			\__(
				'Configure public URL slugs, archives, categories, and URL structures for synced units. The internal post type name in the database stays unchanged.',
				'booking-engine-connector'
			)
		);

		AdminPageLayout::renderSavedNotice();

		if (isset($_GET['bec_error'])) {
			$error = \sanitize_text_field(\wp_unslash((string) $_GET['bec_error']));
			if ($error !== '') {
				echo '<div class="notice notice-error is-dismissible"><p>' . \esc_html($error) . '</p></div>';
			}
		}

		AdminPageLayout::cardOpen(
			\__('Related screens', 'booking-engine-connector'),
			\__('Manage synced content and listing filters from WordPress admin.', 'booking-engine-connector')
		);
		echo '<ul class="bec-admin-links">';
		echo '<li><a href="' . \esc_url(\admin_url('edit.php?post_type=' . UnitPostType::getSlug())) . '">' . \esc_html__(
			'View all units',
			'booking-engine-connector'
		) . '</a></li>';
		echo '<li><a href="' . \esc_url(\admin_url('admin.php?page=' . UnitFiltersPage::PAGE_SLUG)) . '">' . \esc_html__(
			'Listing filters — amenity curation for [bec_unit_filters]',
			'booking-engine-connector'
		) . '</a></li>';
		echo '</ul>';
		AdminPageLayout::cardClose();

		echo '<form method="post" action="' . \esc_url(\admin_url('admin.php')) . '">';
		echo '<input type="hidden" name="page" value="' . \esc_attr(self::PAGE_SLUG) . '" />';
		\wp_nonce_field(self::NONCE_ACTION, 'bec_unit_permalink_nonce');

		AdminPageLayout::cardOpen(
			\__('Unit URLs', 'booking-engine-connector'),
			\__(
				'Changing slugs or URL structures updates rewrite rules on save. Test permalinks after major changes.',
				'booking-engine-connector'
			)
		);

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

		echo '<tr><th scope="row">' . \esc_html__('Unit URL structure', 'booking-engine-connector') . '</th><td>';
		echo '<fieldset>';
		foreach (UnitPermalinkSettings::unitStructureChoices() as $value => $label) {
			echo '<label style="display:block;margin-bottom:0.5em;">';
			echo '<input type="radio" name="bec_unit_url_structure" value="' . \esc_attr($value) . '" ' . \checked($unitStructure, $value, false) . ' /> ';
			echo \esc_html($label);
			echo '</label>';
		}
		echo '</fieldset>';
		echo '<p class="description">' . \sprintf(
			/* translators: %s: example unit URL */
			\esc_html__('Example single unit URL: %s. Units without a category fall back to /{unit slug}/{unit name}.', 'booking-engine-connector'),
			'<code>' . \esc_html($examples['unit']) . '</code>'
		) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		AdminPageLayout::cardClose();

		AdminPageLayout::cardOpen(
			\__('Unit archive', 'booking-engine-connector'),
			\__(
				'When disabled, only single unit URLs are registered.',
				'booking-engine-connector'
			)
		);
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . \esc_html__('Unit archive', 'booking-engine-connector') . '</th><td>';
		echo '<label for="bec_unit_has_archive"><input type="checkbox" name="bec_unit_has_archive" id="bec_unit_has_archive" value="1" ' . \checked($hasArchive, true, false) . ' /> ';
		echo \esc_html__('Enable the public archive page for units (listing URL at the slug above).', 'booking-engine-connector') . '</label>';
		echo '<p class="description">' . \esc_html__(
			'When disabled, only single unit URLs are registered. Changing this updates rewrite rules.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';
		echo '</table>';
		AdminPageLayout::cardClose();

		AdminPageLayout::cardOpen(
			\__('Unit categories', 'booking-engine-connector'),
			\__(
				'Top-level category URLs can conflict with pages or posts that share the same slug. WordPress core content is preferred when a URL is ambiguous. Multilingual plugins (WPML, Polylang) continue to add language prefixes such as /it/ or /es/ automatically.',
				'booking-engine-connector'
			)
		);
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . \esc_html__('Unit categories', 'booking-engine-connector') . '</th><td>';
		echo '<label for="bec_unit_category_enabled"><input type="checkbox" name="bec_unit_category_enabled" id="bec_unit_category_enabled" value="1" ' . \checked($categoryEnabled, true, false) . ' /> ';
		echo \esc_html__('Enable the Unit Category taxonomy (sync assigns categories from the booking engine when supported).', 'booking-engine-connector') . '</label>';
		echo '<p class="description">' . \sprintf(
			/* translators: %s: internal taxonomy key (fixed in the database) */
			\esc_html__('Internal taxonomy key (unchanged): %s', 'booking-engine-connector'),
			'<code>' . \esc_html(UnitCategoryTaxonomy::getSlug()) . '</code>'
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bec_unit_category_permalink_slug">' . \esc_html__('Category URL slug', 'booking-engine-connector') . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="bec_unit_category_permalink_slug" id="bec_unit_category_permalink_slug" value="' . \esc_attr($categorySlugStored) . '" autocomplete="off" placeholder="' . \esc_attr(UnitCategoryTaxonomy::DEFAULT_REWRITE_SLUG) . '" />';
		echo '<p class="description">' . \sprintf(
			/* translators: 1: default slug */
			\esc_html__('Used when category URLs include a dedicated base segment. Leave empty to use the default base “%s”.', 'booking-engine-connector'),
			\esc_html(UnitCategoryTaxonomy::DEFAULT_REWRITE_SLUG)
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . \esc_html__('Category URL structure', 'booking-engine-connector') . '</th><td>';
		echo '<fieldset>';
		foreach (UnitPermalinkSettings::categoryStructureChoices() as $value => $label) {
			echo '<label style="display:block;margin-bottom:0.5em;">';
			echo '<input type="radio" name="bec_unit_category_url_structure" value="' . \esc_attr($value) . '" ' . \checked($categoryStructure, $value, false) . ' /> ';
			echo \esc_html($label);
			echo '</label>';
		}
		echo '</fieldset>';
		echo '<p class="description">' . \sprintf(
			/* translators: %s: example category URL */
			\esc_html__('Example category archive URL: %s.', 'booking-engine-connector'),
			'<code>' . \esc_html($examples['category']) . '</code>'
		) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		AdminPageLayout::cardClose();

		echo '<p class="submit"><button type="submit" class="button button-primary">' . \esc_html__('Save changes', 'booking-engine-connector') . '</button></p>';
		echo '</form>';

		AdminPageLayout::wrapClose();
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

		$unitStructure = isset($_POST['bec_unit_url_structure'])
			? \sanitize_key(\wp_unslash((string) $_POST['bec_unit_url_structure']))
			: UnitPermalinkSettings::UNIT_BASE;
		$categoryStructure = isset($_POST['bec_unit_category_url_structure'])
			? \sanitize_key(\wp_unslash((string) $_POST['bec_unit_category_url_structure']))
			: UnitPermalinkSettings::CAT_CATEGORY_BASE;

		$unitChoices = array_keys(UnitPermalinkSettings::unitStructureChoices());
		$categoryChoices = array_keys(UnitPermalinkSettings::categoryStructureChoices());
		if (! in_array($unitStructure, $unitChoices, true)) {
			$unitStructure = UnitPermalinkSettings::UNIT_BASE;
		}
		if (! in_array($categoryStructure, $categoryChoices, true)) {
			$categoryStructure = UnitPermalinkSettings::CAT_CATEGORY_BASE;
		}

		$categoriesEnabled = isset($_POST['bec_unit_category_enabled']);

		$errors = UnitPermalinkSettings::validationErrors($unitStructure, $categoryStructure, $categoriesEnabled);
		if ($errors !== []) {
			\wp_safe_redirect(
				\add_query_arg(
					[
						'page'      => self::PAGE_SLUG,
						'bec_error' => \rawurlencode($errors[0]),
					],
					\admin_url('admin.php')
				)
			);
			exit;
		}

		$raw = isset($_POST['bec_unit_permalink_slug']) ? \wp_unslash((string) $_POST['bec_unit_permalink_slug']) : '';
		$raw = trim($raw);
		if ($raw === '') {
			\update_option(UnitPostType::OPTION_PERMALINK_SLUG, '', false);
		} else {
			\update_option(UnitPostType::OPTION_PERMALINK_SLUG, \sanitize_title($raw), false);
		}

		\update_option(UnitPostType::OPTION_HAS_ARCHIVE, isset($_POST['bec_unit_has_archive']), false);

		\update_option(UnitCategoryTaxonomy::OPTION_ENABLED, $categoriesEnabled, false);

		$rawCatSlug = isset($_POST['bec_unit_category_permalink_slug']) ? \wp_unslash((string) $_POST['bec_unit_category_permalink_slug']) : '';
		$rawCatSlug = \trim($rawCatSlug);
		if ($rawCatSlug === '') {
			\update_option(UnitCategoryTaxonomy::OPTION_PERMALINK_SLUG, '', false);
		} else {
			\update_option(UnitCategoryTaxonomy::OPTION_PERMALINK_SLUG, \sanitize_title($rawCatSlug), false);
		}

		\update_option(UnitPermalinkSettings::OPTION_UNIT_STRUCTURE, $unitStructure, false);
		\update_option(UnitPermalinkSettings::OPTION_CATEGORY_STRUCTURE, $categoryStructure, false);

		\flush_rewrite_rules(false);

		\wp_safe_redirect(\add_query_arg(['page' => self::PAGE_SLUG, 'bec_saved' => '1'], \admin_url('admin.php')));
		exit;
	}
}
