<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin\Settings;

use BookingEngineConnector\Admin\AdminMenu;
use BookingEngineConnector\Admin\AdminPageLayout;
use BookingEngineConnector\Taxonomies\UnitAmenityTaxonomy;
use BookingEngineConnector\UnitFilters\UnitAmenityIndexer;
use BookingEngineConnector\UnitFilters\UnitFilterSettings;

/**
 * Admin curation for which indexed amenities appear in `[bec_unit_filters]`.
 */
final class UnitFiltersPage
{
	public const PAGE_SLUG = 'bec-unit-filters';

	private const NONCE_ACTION = 'bec_unit_filters_save';

	public static function register(): void
	{
		\add_action('admin_init', [self::class, 'handlePost']);
	}

	public static function render(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$options = UnitFilterSettings::getAmenityOptions();
		$terms   = self::discoverIndexedAmenities();
		$search  = isset($_GET['bec_amenity_search'])
			? \sanitize_text_field(\wp_unslash((string) $_GET['bec_amenity_search']))
			: '';

		AdminPageLayout::wrapOpen(
			\__('Listing Filters', 'booking-engine-connector'),
			\__(
				'Choose which indexed amenities appear in the [bec_unit_filters] shortcode. Styling for the filter form is under Design.',
				'booking-engine-connector'
			),
			'bec-unit-filters-admin'
		);

		AdminPageLayout::renderSavedNotice();

		if (! UnitAmenityIndexer::isIndexComplete()) {
			AdminPageLayout::inlineNotice(
				\__(
					'Amenity index is still building in the background. Options may be incomplete until indexing finishes. Run a sync if the list stays empty.',
					'booking-engine-connector'
				),
				'warning'
			);
		}

		AdminPageLayout::cardOpen(
			\__('Search amenities', 'booking-engine-connector'),
			\__('Filter the table by amenity key or label.', 'booking-engine-connector')
		);
		echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '" class="bec-unit-filters-admin__search">';
		echo '<input type="hidden" name="page" value="' . \esc_attr(self::PAGE_SLUG) . '" />';
		echo '<p class="search-box">';
		echo '<label class="screen-reader-text" for="bec_amenity_search">' . \esc_html__(
			'Search amenities',
			'booking-engine-connector'
		) . '</label>';
		echo '<input type="search" id="bec_amenity_search" name="bec_amenity_search" value="' . \esc_attr($search) . '" />';
		echo '<input type="submit" class="button" value="' . \esc_attr__('Search', 'booking-engine-connector') . '" />';
		echo '</p></form>';
		AdminPageLayout::cardClose();

		echo '<form method="post" action="' . \esc_url(\admin_url('admin.php')) . '">';
		echo '<input type="hidden" name="page" value="' . \esc_attr(self::PAGE_SLUG) . '" />';
		\wp_nonce_field(self::NONCE_ACTION, 'bec_unit_filters_nonce');

		AdminPageLayout::cardOpen(
			\__('Amenities', 'booking-engine-connector'),
			\__(
				'Enable amenities for the filter shortcode and optionally override display labels. Unit counts reflect indexed taxonomy terms.',
				'booking-engine-connector'
			)
		);

		echo '<table class="widefat striped bec-unit-filters-admin__table">';
		echo '<thead><tr>';
		echo '<th class="check-column"><input type="checkbox" id="bec-amenity-toggle-all" /></th>';
		echo '<th>' . \esc_html__('Amenity', 'booking-engine-connector') . '</th>';
		echo '<th>' . \esc_html__('Category', 'booking-engine-connector') . '</th>';
		echo '<th>' . \esc_html__('Units', 'booking-engine-connector') . '</th>';
		echo '<th>' . \esc_html__('Display label (optional)', 'booking-engine-connector') . '</th>';
		echo '</tr></thead><tbody>';

		if ($terms === []) {
			echo '<tr><td colspan="5">' . \esc_html__(
				'No indexed amenities yet. Sync units from the booking engine first, then return here to curate the filter list.',
				'booking-engine-connector'
			) . '</td></tr>';
		}

		$needle = \strtolower($search);
		foreach ($terms as $row) {
			$key   = $row['key'];
			$label = $row['label'];
			if ($needle !== '' && \strpos(\strtolower($key . ' ' . $label), $needle) === false) {
				continue;
			}

			$checked = \in_array($key, $options['enabled'], true);
			$custom  = $options['labels'][ $key ] ?? '';

			echo '<tr>';
			echo '<th scope="row" class="check-column">';
			echo '<input type="checkbox" name="bec_unit_filter_amenity_enabled[]" value="' . \esc_attr($key) . '" ' . \checked($checked, true, false) . ' class="bec-amenity-enable" />';
			echo '</th>';
			echo '<td><code>' . \esc_html($key) . '</code><br /><span class="description">' . \esc_html($label) . '</span></td>';
			echo '<td>' . \esc_html($row['category'] !== '' ? $row['category'] : '—') . '</td>';
			echo '<td>' . \esc_html((string) $row['count']) . '</td>';
			echo '<td><input type="text" class="regular-text" name="bec_unit_filter_amenity_label[' . \esc_attr($key) . ']" value="' . \esc_attr($custom) . '" placeholder="' . \esc_attr($label) . '" /></td>';
			echo '</tr>';
			echo '<input type="hidden" name="bec_unit_filter_amenity_order[]" value="' . \esc_attr($key) . '" />';
		}

		echo '</tbody></table>';
		AdminPageLayout::cardClose();

		echo '<p class="submit"><button type="submit" class="button button-primary">' . \esc_html__(
			'Save changes',
			'booking-engine-connector'
		) . '</button></p>';
		echo '</form>';

		echo '<script>
		document.getElementById("bec-amenity-toggle-all")?.addEventListener("change", function(e) {
			document.querySelectorAll(".bec-amenity-enable").forEach(function(cb) { cb.checked = e.target.checked; });
		});
		</script>';

		AdminPageLayout::wrapClose();
	}

	public static function handlePost(): void
	{
		if (! isset($_POST['page'], $_POST['bec_unit_filters_nonce']) || (string) \sanitize_key(\wp_unslash((string) $_POST['page'])) !== self::PAGE_SLUG) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		\check_admin_referer(self::NONCE_ACTION, 'bec_unit_filters_nonce');

		$enabled = [];
		if (isset($_POST['bec_unit_filter_amenity_enabled']) && \is_array($_POST['bec_unit_filter_amenity_enabled'])) {
			foreach ($_POST['bec_unit_filter_amenity_enabled'] as $key) {
				$key = \sanitize_key(\wp_unslash((string) $key));
				if ($key !== '') {
					$enabled[] = $key;
				}
			}
		}

		$order = [];
		if (isset($_POST['bec_unit_filter_amenity_order']) && \is_array($_POST['bec_unit_filter_amenity_order'])) {
			foreach ($_POST['bec_unit_filter_amenity_order'] as $key) {
				$key = \sanitize_key(\wp_unslash((string) $key));
				if ($key !== '') {
					$order[] = $key;
				}
			}
		}

		$labels = [];
		if (isset($_POST['bec_unit_filter_amenity_label']) && \is_array($_POST['bec_unit_filter_amenity_label'])) {
			foreach ($_POST['bec_unit_filter_amenity_label'] as $key => $label) {
				$key = \sanitize_key((string) $key);
				$label = \sanitize_text_field(\wp_unslash((string) $label));
				if ($key !== '' && $label !== '') {
					$labels[ $key ] = $label;
				}
			}
		}

		UnitFilterSettings::saveAmenityOptions([
			'enabled' => $enabled,
			'order'   => $order,
			'labels'  => $labels,
		]);

		\wp_safe_redirect(\add_query_arg(['page' => self::PAGE_SLUG, 'bec_saved' => '1'], \admin_url('admin.php')));
		exit;
	}

	/**
	 * @return list<array{key: string, label: string, category: string, count: int}>
	 */
	private static function discoverIndexedAmenities(): array
	{
		$terms = \get_terms([
			'taxonomy'   => UnitAmenityTaxonomy::TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		]);

		if (\is_wp_error($terms) || ! \is_array($terms)) {
			return self::discoverAmenitiesFromMetaFallback();
		}

		$out = [];
		foreach ($terms as $term) {
			if (! $term instanceof \WP_Term) {
				continue;
			}
			$category = (string) \get_term_meta($term->term_id, UnitAmenityTaxonomy::TERM_META_CATEGORY, true);
			$out[] = [
				'key'      => $term->slug,
				'label'    => UnitAmenityTaxonomy::resolveLocalizedLabelForTerm($term),
				'category' => $category,
				'count'    => (int) $term->count,
			];
		}

		return $out;
	}

	/**
	 * @return list<array{key: string, label: string, category: string, count: int}>
	 */
	private static function discoverAmenitiesFromMetaFallback(): array
	{
		global $wpdb;

		$postType = \esc_sql(\BookingEngineConnector\PostTypes\UnitPostType::getSlug());
		$metaKey  = 'bec_core_amenities';
		$sql      = "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = '{$postType}' AND pm.meta_key = %s AND pm.meta_value <> ''";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col($wpdb->prepare($sql, $metaKey));
		if (! \is_array($rows)) {
			return [];
		}

		/** @var array<string, array{key: string, label: string, category: string, count: int}> $map */
		$map = [];
		foreach ($rows as $raw) {
			$decoded = \json_decode((string) $raw, true);
			if (! \is_array($decoded)) {
				continue;
			}
			foreach (\BookingEngineConnector\Units\AmenityItem::normalizeList($decoded) as $item) {
				$key = $item['key'];
				if ($key === '') {
					continue;
				}
				if (! isset($map[ $key ])) {
					$labels = $item['labels'] ?? [];
					$label  = UnitAmenityTaxonomy::resolveLocalizedLabelFromNames($labels);
					$map[ $key ] = [
						'key'      => $key,
						'label'    => $label !== '' ? $label : $key,
						'category' => isset($item['category']) ? (string) $item['category'] : '',
						'count'    => 0,
					];
				}
				$map[ $key ]['count']++;
			}
		}

		$out = \array_values($map);
		\usort($out, static fn (array $a, array $b): int => \strcmp($a['label'], $b['label']));

		return $out;
	}
}
