<?php

declare(strict_types=1);

namespace BookingEngineConnector\Logging;

/**
 * Persists rows into {@see wp_bec_api_log}. Never stores secrets or Authorization headers.
 */
final class ApiLogRepository
{
	/**
	 * @param array{
	 *   provider_slug: string,
	 *   http_method: string,
	 *   endpoint: string,
	 *   status_code: int,
	 *   duration_ms: int,
	 *   error_category?: ?string,
	 *   message?: ?string,
	 *   unit_id?: ?int,
	 *   correlation_id?: ?string,
	 * } $row
	 */
	public function insert(array $row): int
	{
		global $wpdb;

		$table = $wpdb->prefix . 'bec_api_log';

		$data = [
			'created_at'     => \current_time('mysql'),
			'provider_slug'  => \sanitize_text_field($row['provider_slug']),
			'http_method'    => \sanitize_text_field($row['http_method']),
			'endpoint'       => \sanitize_text_field($row['endpoint']),
			'status_code'    => (int) $row['status_code'],
			'duration_ms'    => max(0, (int) $row['duration_ms']),
			'error_category' => isset($row['error_category']) ? $this->nullableString($row['error_category'], 32) : null,
			'message'        => isset($row['message']) ? $this->nullableText($row['message']) : null,
			'unit_id'        => isset($row['unit_id']) && $row['unit_id'] !== null ? max(0, (int) $row['unit_id']) : null,
			'correlation_id' => isset($row['correlation_id']) ? $this->nullableString($row['correlation_id'], 64) : null,
		];

		$formats = ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- repository layer for custom table.
		$wpdb->insert($table, $data, $formats);

		return (int) $wpdb->insert_id;
	}

	private function nullableString(?string $value, int $maxLen): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}

		return \mb_substr(\sanitize_text_field($value), 0, $maxLen);
	}

	private function nullableText(?string $value): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}

		return \mb_substr(\wp_strip_all_tags($value), 0, 2000);
	}
}
