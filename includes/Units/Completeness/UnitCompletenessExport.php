<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units\Completeness;

/**
 * CSV export for unit mandatory field completeness.
 */
final class UnitCompletenessExport
{
	public const ACTION = 'bec_export_unit_completeness';

	public const NONCE_ACTION = 'bec_export_unit_completeness';

	public static function register(): void
	{
		\add_action('admin_post_' . self::ACTION, [self::class, 'handleDownload']);
	}

	public static function downloadUrl(): string
	{
		return \wp_nonce_url(
			\admin_url('admin-post.php?action=' . self::ACTION),
			self::NONCE_ACTION,
			'_wpnonce'
		);
	}

	public static function handleDownload(): void
	{
		if (! \current_user_can(\BookingEngineConnector\Admin\AdminMenu::CAPABILITY)) {
			\wp_die(\esc_html__('You do not have permission to export unit data.', 'booking-engine-connector'));
		}

		\check_admin_referer(self::NONCE_ACTION);

		$enabledFields = UnitMandatoryFieldSettings::getEnabledFields();
		$postIds       = UnitCompletenessChecker::getScopedPostIds();

		$filename = 'bec-unit-completeness-' . \gmdate('Y-m-d') . '.csv';

		\nocache_headers();
		\header('Content-Type: text/csv; charset=utf-8');
		\header('Content-Disposition: attachment; filename="' . $filename . '"');

		$output = \fopen('php://output', 'w');
		if ($output === false) {
			\wp_die(\esc_html__('Could not open export stream.', 'booking-engine-connector'));
		}

		// UTF-8 BOM for Excel.
		\fwrite($output, "\xEF\xBB\xBF");

		$header = [
			'unit_id',
			'unit_name',
			'external_id',
			'provider',
			'edit_url',
		];

		foreach ($enabledFields as $fieldId) {
			$header[] = $fieldId;
		}

		\fputcsv($output, $header);

		foreach ($postIds as $postId) {
			$result = UnitCompletenessChecker::checkUnit($postId);
			$row    = [
				(string) $result['post_id'],
				$result['title'],
				$result['external_id'],
				$result['provider'],
				$result['edit_url'],
			];

			foreach ($enabledFields as $fieldId) {
				$status = $result['field_status'][ $fieldId ] ?? \__('Missing', 'booking-engine-connector');
				$row[]  = $status;
			}

			\fputcsv($output, $row);
		}

		\fclose($output);
		exit;
	}
}
