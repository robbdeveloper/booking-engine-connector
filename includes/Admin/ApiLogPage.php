<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin;

/**
 * Admin UI for structured API request logs (TASK-LOG-003 minimal).
 */
final class ApiLogPage
{
	public static function render(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'bec_api_log';

		$filterProvider = isset($_GET['bec_provider']) ? \sanitize_text_field(\wp_unslash((string) $_GET['bec_provider'])) : '';
		$filterStatus   = isset($_GET['bec_status']) ? \sanitize_text_field(\wp_unslash((string) $_GET['bec_status'])) : '';

		$where  = [];
		$params = [];

		if ($filterProvider !== '') {
			$where[]  = 'provider_slug = %s';
			$params[] = $filterProvider;
		}

		if ($filterStatus !== '' && \ctype_digit($filterStatus)) {
			$where[]  = 'status_code = %d';
			$params[] = (int) $filterStatus;
		}

		$sql = "SELECT * FROM `{$table}`";

		if ($where !== []) {
			$sql .= ' WHERE ' . \implode(' AND ', $where);
		}

		$sql .= ' ORDER BY id DESC LIMIT %d';
		$params[] = 200;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built with placeholders below.
		$prepared = $wpdb->prepare($sql, ...$params);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results($prepared, \ARRAY_A);

		if (! \is_array($rows)) {
			$rows = [];
		}

		$providers = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT DISTINCT provider_slug FROM `{$table}` ORDER BY provider_slug ASC"
		);

		if (! \is_array($providers)) {
			$providers = [];
		}

		AdminPageLayout::wrapOpen(
			\__('Tools & Logs', 'booking-engine-connector'),
			\__(
				'Review recent provider API requests and filter by provider or HTTP status.',
				'booking-engine-connector'
			)
		);

		AdminPageLayout::cardOpen(
			\__('API request log', 'booking-engine-connector'),
			\__(
				'Auth/token rows are omitted by default unless the bec_log_auth_requests filter is enabled.',
				'booking-engine-connector'
			)
		);

		echo '<form method="get" class="bec-api-log-filters">';
		echo '<input type="hidden" name="page" value="bec-api-log" />';

		echo '<label for="bec_provider">' . \esc_html__('Provider', 'booking-engine-connector') . '</label> ';
		echo '<select name="bec_provider" id="bec_provider">';
		echo '<option value="">' . \esc_html__('All', 'booking-engine-connector') . '</option>';
		foreach ($providers as $p) {
			$selected = \selected($filterProvider, (string) $p, false);
			echo '<option value="' . \esc_attr((string) $p) . '" ' . $selected . '>' . \esc_html((string) $p) . '</option>';
		}
		echo '</select> ';

		echo '<label for="bec_status">' . \esc_html__('HTTP status', 'booking-engine-connector') . '</label> ';
		echo '<input type="text" name="bec_status" id="bec_status" value="' . \esc_attr($filterStatus) . '" placeholder="200" /> ';

		\submit_button(\__('Filter', 'booking-engine-connector'), 'secondary', '', false);
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr>';
		$headers = [
			\__('ID', 'booking-engine-connector'),
			\__('Time', 'booking-engine-connector'),
			\__('Provider', 'booking-engine-connector'),
			\__('Method', 'booking-engine-connector'),
			\__('Endpoint', 'booking-engine-connector'),
			\__('Status', 'booking-engine-connector'),
			\__('Duration (ms)', 'booking-engine-connector'),
			\__('Correlation', 'booking-engine-connector'),
			\__('Message', 'booking-engine-connector'),
		];
		foreach ($headers as $h) {
			echo '<th>' . \esc_html($h) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ($rows === []) {
			echo '<tr><td colspan="9">' . \esc_html__('No log entries yet.', 'booking-engine-connector') . '</td></tr>';
		} else {
			foreach ($rows as $row) {
				echo '<tr>';
				echo '<td>' . \esc_html((string) ($row['id'] ?? '')) . '</td>';
				echo '<td>' . \esc_html((string) ($row['created_at'] ?? '')) . '</td>';
				echo '<td>' . \esc_html((string) ($row['provider_slug'] ?? '')) . '</td>';
				echo '<td>' . \esc_html((string) ($row['http_method'] ?? '')) . '</td>';
				echo '<td>' . \esc_html((string) ($row['endpoint'] ?? '')) . '</td>';
				echo '<td>' . \esc_html((string) ($row['status_code'] ?? '')) . '</td>';
				echo '<td>' . \esc_html((string) ($row['duration_ms'] ?? '')) . '</td>';
				echo '<td>' . \esc_html((string) ($row['correlation_id'] ?? '')) . '</td>';
				echo '<td>' . \esc_html((string) ($row['message'] ?? '')) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		AdminPageLayout::cardClose();

		AdminPageLayout::cardOpen(
			\__('Troubleshooting', 'booking-engine-connector'),
			\__('Quick links when diagnosing connection or sync issues.', 'booking-engine-connector')
		);
		echo '<ul class="bec-admin-links">';
		echo '<li><a href="' . \esc_url(\admin_url('admin.php?page=' . Settings\ConnectionPage::PAGE_SLUG)) . '">' . \esc_html__(
			'Verify provider connection',
			'booking-engine-connector'
		) . '</a></li>';
		echo '<li><a href="' . \esc_url(SyncAdmin::adminPageUrl(SyncAdmin::TAB_TOOLS)) . '">' . \esc_html__(
			'Open sync tools',
			'booking-engine-connector'
		) . '</a></li>';
		echo '</ul>';
		AdminPageLayout::cardClose();

		AdminPageLayout::wrapClose();
	}
}
