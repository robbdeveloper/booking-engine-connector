<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin\Settings;

use BookingEngineConnector\Admin\AdminMenu;
use BookingEngineConnector\Admin\AdminPageLayout;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Units\Completeness\UnitCompletenessExport;
use BookingEngineConnector\Units\Completeness\UnitCompletenessReport;
use BookingEngineConnector\Units\Completeness\UnitMandatoryFieldRegistry;
use BookingEngineConnector\Units\Completeness\UnitMandatoryFieldSettings;

/**
 * Admin settings for mandatory unit field checks.
 */
final class UnitDataQualityPage
{
	public const PAGE_SLUG = 'bec-unit-quality';

	private const NONCE_ACTION = 'bec_unit_quality_save';

	public static function register(): void
	{
		\add_action('admin_init', [self::class, 'handlePost']);
	}

	public static function render(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$settings      = UnitMandatoryFieldSettings::getSettings();
		$enabled       = $settings['enabled_fields'];
		$galleryMin    = $settings['gallery_min_count'];
		$definitions   = UnitMandatoryFieldRegistry::definitions();
		$groupLabels   = UnitMandatoryFieldRegistry::groupLabels();
		$summary       = UnitCompletenessReport::getSummary(true);
		$galleryChecked = \in_array(UnitMandatoryFieldRegistry::FIELD_GALLERY, $enabled, true);

		AdminPageLayout::wrapOpen(
			\__('Unit data quality', 'booking-engine-connector'),
			\__(
				'Choose which canonical unit fields must be filled after sync. Incomplete units are flagged in the admin and can be exported as CSV for client follow-up.',
				'booking-engine-connector'
			),
			'bec-unit-quality-admin'
		);

		AdminPageLayout::renderSavedNotice();

		if ($summary['total'] > 0) {
			if ($summary['incomplete'] > 0) {
				AdminPageLayout::inlineNotice(
					\sprintf(
						/* translators: 1: incomplete count, 2: total count */
						\esc_html__('%1$d of %2$d published units are missing one or more mandatory fields.', 'booking-engine-connector'),
						(int) $summary['incomplete'],
						(int) $summary['total']
					),
					'warning'
				);
			} else {
				AdminPageLayout::inlineNotice(
					\sprintf(
						/* translators: %d: total unit count */
						\esc_html__('All %d published units have the required fields.', 'booking-engine-connector'),
						(int) $summary['total']
					),
					'success'
				);
			}
		}

		echo '<p class="bec-unit-quality-admin__actions">';
		echo '<a class="button" href="' . \esc_url(\admin_url('edit.php?post_type=' . UnitPostType::getSlug() . '&bec_incomplete=1')) . '">';
		echo \esc_html__('View incomplete units', 'booking-engine-connector');
		echo '</a> ';
		echo '<a class="button" href="' . \esc_url(UnitCompletenessExport::downloadUrl()) . '">';
		echo \esc_html__('Download CSV', 'booking-engine-connector');
		echo '</a>';
		echo '</p>';

		echo '<form method="post" action="' . \esc_url(\admin_url('admin.php')) . '">';
		echo '<input type="hidden" name="page" value="' . \esc_attr(self::PAGE_SLUG) . '" />';
		\wp_nonce_field(self::NONCE_ACTION, 'bec_unit_quality_nonce');

		AdminPageLayout::cardOpen(
			\__('Mandatory fields', 'booking-engine-connector'),
			\__(
				'Checked fields must be present on each published unit (canonical posts only when translations exist). Provider data is not queried — only what is stored in WordPress after sync.',
				'booking-engine-connector'
			)
		);

		$grouped = [];
		foreach ($definitions as $fieldId => $def) {
			$group = (string) ( $def['group'] ?? 'other' );
			if (! isset($grouped[ $group ])) {
				$grouped[ $group ] = [];
			}
			$grouped[ $group ][ $fieldId ] = $def;
		}

		$groupOrder = [ 'content', 'location', 'capacity', 'media', 'other' ];
		foreach ($groupOrder as $groupKey) {
			if (! isset($grouped[ $groupKey ]) || $grouped[ $groupKey ] === []) {
				continue;
			}

			$groupLabel = $groupLabels[ $groupKey ] ?? $groupKey;
			echo '<h3 class="bec-unit-quality-admin__group-title">' . \esc_html($groupLabel) . '</h3>';
			echo '<table class="widefat striped bec-unit-quality-admin__table"><tbody>';

			foreach ($grouped[ $groupKey ] as $fieldId => $def) {
				$checked = \in_array($fieldId, $enabled, true);
				echo '<tr>';
				echo '<th scope="row" style="width:16rem;">';
				echo '<label><input type="checkbox" name="bec_mandatory_fields[]" value="' . \esc_attr($fieldId) . '" ' . \checked($checked, true, false);
				if ($fieldId === UnitMandatoryFieldRegistry::FIELD_GALLERY) {
					echo ' class="bec-mandatory-gallery-toggle"';
				}
				echo ' /> ' . \esc_html((string) $def['label']) . '</label>';
				echo '</th>';
				echo '<td><code>' . \esc_html($fieldId) . '</code></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<p id="bec-gallery-min-wrap" style="margin-top:1em;' . ( $galleryChecked ? '' : ' display:none;' ) . '">';
		echo '<label for="bec_gallery_min_count"><strong>' . \esc_html__('Minimum gallery images', 'booking-engine-connector') . '</strong></label><br />';
		echo '<input type="number" min="1" step="1" class="small-text" name="bec_gallery_min_count" id="bec_gallery_min_count" value="' . \esc_attr((string) $galleryMin) . '" />';
		echo '<span class="description"> ' . \esc_html__(
			'Applies when Gallery is mandatory. Featured image is checked separately.',
			'booking-engine-connector'
		) . '</span>';
		echo '</p>';

		AdminPageLayout::cardClose();

		echo '<p class="submit"><button type="submit" class="button button-primary">' . \esc_html__(
			'Save changes',
			'booking-engine-connector'
		) . '</button></p>';
		echo '</form>';

		echo '<script>
		(function() {
			var galleryToggle = document.querySelector(".bec-mandatory-gallery-toggle");
			var wrap = document.getElementById("bec-gallery-min-wrap");
			if (!galleryToggle || !wrap) return;
			galleryToggle.addEventListener("change", function() {
				wrap.style.display = galleryToggle.checked ? "" : "none";
			});
		})();
		</script>';

		AdminPageLayout::wrapClose();
	}

	public static function handlePost(): void
	{
		if (! isset($_POST['page'], $_POST['bec_unit_quality_nonce']) || (string) \sanitize_key(\wp_unslash((string) $_POST['page'])) !== self::PAGE_SLUG) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		\check_admin_referer(self::NONCE_ACTION, 'bec_unit_quality_nonce');

		$enabled = [];
		if (isset($_POST['bec_mandatory_fields']) && \is_array($_POST['bec_mandatory_fields'])) {
			foreach ($_POST['bec_mandatory_fields'] as $fieldId) {
				$fieldId = \sanitize_key(\wp_unslash((string) $fieldId));
				if ($fieldId !== '') {
					$enabled[] = $fieldId;
				}
			}
		}

		$galleryMin = isset($_POST['bec_gallery_min_count']) ? (int) $_POST['bec_gallery_min_count'] : UnitMandatoryFieldSettings::defaultGalleryMinCount();

		UnitMandatoryFieldSettings::save([
			'enabled_fields'    => $enabled,
			'gallery_min_count' => $galleryMin,
		]);

		UnitCompletenessReport::clearCache();

		\wp_safe_redirect(\add_query_arg(['page' => self::PAGE_SLUG, 'bec_saved' => '1'], \admin_url('admin.php')));
		exit;
	}
}
