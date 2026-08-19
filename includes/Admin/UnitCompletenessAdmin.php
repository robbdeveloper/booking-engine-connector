<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin;

use BookingEngineConnector\Admin\Settings\UnitDataQualityPage;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Units\Completeness\UnitCompletenessChecker;
use BookingEngineConnector\Units\Completeness\UnitCompletenessExport;
use BookingEngineConnector\Units\Completeness\UnitCompletenessReport;
use BookingEngineConnector\Units\Completeness\UnitMandatoryFieldSettings;

/**
 * Admin UI for unit completeness: list table, notices, and WP dashboard widget.
 */
final class UnitCompletenessAdmin
{
	public static function register(): void
	{
		\add_action('admin_notices', [self::class, 'renderUnitsListNotice']);
		\add_filter('views_edit-' . UnitPostType::getSlug(), [self::class, 'addIncompleteView']);
		\add_action('pre_get_posts', [self::class, 'filterIncompleteUnits']);
		\add_action('wp_dashboard_setup', [self::class, 'registerDashboardWidget']);
		\add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
	}

	public static function enqueueAssets(string $hookSuffix): void
	{
		if ($hookSuffix !== 'edit.php') {
			return;
		}

		$postType = isset($_GET['post_type'])
			? \sanitize_key(\wp_unslash((string) $_GET['post_type']))
			: '';

		if ($postType !== UnitPostType::getSlug()) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		\wp_enqueue_style(
			'bec-admin',
			\BEC_PLUGIN_URL . 'assets/admin.css',
			[],
			\BEC_VERSION
		);
	}

	public static function renderUnitsListNotice(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$screen = \function_exists('get_current_screen') ? \get_current_screen() : null;
		if ($screen === null || $screen->id !== 'edit-' . UnitPostType::getSlug()) {
			return;
		}

		if (UnitMandatoryFieldSettings::getEnabledFields() === []) {
			return;
		}

		$summary = UnitCompletenessReport::getSummary();
		if ($summary['total'] === 0) {
			return;
		}

		if ($summary['incomplete'] === 0) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo \esc_html__(
				'All published units have the required mandatory fields.',
				'booking-engine-connector'
			);
			echo ' <a href="' . \esc_url(\admin_url('admin.php?page=' . UnitDataQualityPage::PAGE_SLUG)) . '">';
			echo \esc_html__('Configure mandatory fields', 'booking-engine-connector');
			echo '</a>';
			echo '</p></div>';

			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo \esc_html(
			\sprintf(
				/* translators: 1: incomplete count, 2: total count */
				\__('%1$d of %2$d units are missing mandatory fields.', 'booking-engine-connector'),
				(int) $summary['incomplete'],
				(int) $summary['total']
			)
		);
		echo ' <a href="' . \esc_url(\admin_url('edit.php?post_type=' . UnitPostType::getSlug() . '&bec_incomplete=1')) . '">';
		echo \esc_html__('View incomplete', 'booking-engine-connector');
		echo '</a> · ';
		echo '<a href="' . \esc_url(\admin_url('admin.php?page=' . UnitDataQualityPage::PAGE_SLUG)) . '">';
		echo \esc_html__('Configure fields', 'booking-engine-connector');
		echo '</a> · ';
		echo '<a href="' . \esc_url(UnitCompletenessExport::downloadUrl()) . '">';
		echo \esc_html__('Download CSV', 'booking-engine-connector');
		echo '</a>';
		echo '</p></div>';
	}

	/**
	 * @param array<string, string> $views
	 *
	 * @return array<string, string>
	 */
	public static function addIncompleteView(array $views): array
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return $views;
		}

		if (UnitMandatoryFieldSettings::getEnabledFields() === []) {
			return $views;
		}

		$summary   = UnitCompletenessReport::getSummary();
		$isActive  = isset($_GET['bec_incomplete']) && (string) \wp_unslash((string) $_GET['bec_incomplete']) === '1';
		$class     = $isActive ? 'current' : '';
		$count     = (int) $summary['incomplete'];
		$url       = \admin_url('edit.php?post_type=' . UnitPostType::getSlug() . '&bec_incomplete=1');
		$label     = \sprintf(
			/* translators: %s: number of incomplete units */
			\__('Incomplete <span class="count">(%s)</span>', 'booking-engine-connector'),
			\number_format_i18n($count)
		);

		$views['bec_incomplete'] = '<a href="' . \esc_url($url) . '" class="' . \esc_attr($class) . '">' . $label . '</a>';

		return $views;
	}

	public static function filterIncompleteUnits(\WP_Query $query): void
	{
		if (! \is_admin() || ! $query->is_main_query()) {
			return;
		}

		$screen = \function_exists('get_current_screen') ? \get_current_screen() : null;
		if ($screen === null || $screen->id !== 'edit-' . UnitPostType::getSlug()) {
			return;
		}

		if (! isset($_GET['bec_incomplete']) || (string) \wp_unslash((string) $_GET['bec_incomplete']) !== '1') {
			return;
		}

		$postIds = UnitCompletenessReport::getIncompletePostIds();
		if ($postIds === []) {
			$query->set('post__in', [0]);

			return;
		}

		$query->set('post__in', $postIds);
	}

	public static function registerDashboardWidget(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		\wp_add_dashboard_widget(
			'bec_unit_completeness',
			\__('Booking Engine — unit data quality', 'booking-engine-connector'),
			[self::class, 'renderDashboardWidget']
		);
	}

	public static function renderDashboardWidget(): void
	{
		if (UnitMandatoryFieldSettings::getEnabledFields() === []) {
			echo '<p>' . \esc_html__(
				'No mandatory fields are configured yet.',
				'booking-engine-connector'
			) . '</p>';
			echo '<p><a class="button" href="' . \esc_url(\admin_url('admin.php?page=' . UnitDataQualityPage::PAGE_SLUG)) . '">';
			echo \esc_html__('Configure mandatory fields', 'booking-engine-connector');
			echo '</a></p>';

			return;
		}

		$summary = UnitCompletenessReport::getSummary();

		if ($summary['total'] === 0) {
			echo '<p>' . \esc_html__('No published units yet.', 'booking-engine-connector') . '</p>';

			return;
		}

		if ($summary['incomplete'] === 0) {
			echo '<p>' . \esc_html(
				\sprintf(
					/* translators: %d: total unit count */
					\__('All %d published units have the required fields.', 'booking-engine-connector'),
					(int) $summary['total']
				)
			) . '</p>';
		} else {
			echo '<p><strong>' . \esc_html(
				\sprintf(
					/* translators: 1: incomplete count, 2: total count */
					\__('%1$d of %2$d units are missing mandatory fields.', 'booking-engine-connector'),
					(int) $summary['incomplete'],
					(int) $summary['total']
				)
			) . '</strong></p>';

			echo '<ul style="margin:0.5em 0 1em 1.2em; list-style:disc;">';
			$shown = 0;
			foreach ($summary['incomplete_units'] as $unit) {
				if ($shown >= 5) {
					break;
				}
				$missingLabels = [];
				foreach ($unit['missing'] as $fieldId) {
					$missingLabels[] = \BookingEngineConnector\Units\Completeness\UnitMandatoryFieldRegistry::labelFor($fieldId);
				}
				echo '<li><a href="' . \esc_url($unit['edit_url']) . '">' . \esc_html($unit['title']) . '</a>';
				if ($missingLabels !== []) {
					echo ' — <span class="description">' . \esc_html(\implode(', ', $missingLabels)) . '</span>';
				}
				echo '</li>';
				$shown++;
			}
			if ($summary['incomplete'] > 5) {
				echo '<li class="description">' . \esc_html(
					\sprintf(
						/* translators: %d: additional incomplete unit count */
						\__('…and %d more.', 'booking-engine-connector'),
						(int) $summary['incomplete'] - 5
					)
				) . '</li>';
			}
			echo '</ul>';
		}

		echo '<p>';
		echo '<a class="button" href="' . \esc_url(\admin_url('admin.php?page=' . UnitDataQualityPage::PAGE_SLUG)) . '">';
		echo \esc_html__('Configure fields', 'booking-engine-connector');
		echo '</a> ';
		echo '<a class="button" href="' . \esc_url(UnitCompletenessExport::downloadUrl()) . '">';
		echo \esc_html__('Download CSV', 'booking-engine-connector');
		echo '</a>';
		if ($summary['incomplete'] > 0) {
			echo ' <a class="button" href="' . \esc_url(\admin_url('edit.php?post_type=' . UnitPostType::getSlug() . '&bec_incomplete=1')) . '">';
			echo \esc_html__('View incomplete', 'booking-engine-connector');
			echo '</a>';
		}
		echo '</p>';
	}

	/**
	 * Completeness badge HTML for the units list table.
	 */
	public static function renderListColumn(int $postId): void
	{
		if (UnitMandatoryFieldSettings::getEnabledFields() === []) {
			echo '<span aria-hidden="true">—</span>';

			return;
		}

		$result = UnitCompletenessChecker::checkUnit($postId);
		$editUrl = \get_edit_post_link($postId);

		if ($result['missing'] === []) {
			echo '<span class="bec-completeness-badge bec-completeness-badge--ok">' . \esc_html__('Complete', 'booking-engine-connector') . '</span>';

			return;
		}

		$count = \count($result['missing']);
		$label = \sprintf(
			/* translators: %d: number of missing mandatory fields */
			\_n('Missing (%d)', 'Missing (%d)', $count, 'booking-engine-connector'),
			$count
		);

		if (\is_string($editUrl) && $editUrl !== '') {
			echo '<a href="' . \esc_url($editUrl) . '" class="bec-completeness-badge bec-completeness-badge--warn">' . \esc_html($label) . '</a>';
		} else {
			echo '<span class="bec-completeness-badge bec-completeness-badge--warn">' . \esc_html($label) . '</span>';
		}
	}
}
