<?php

declare(strict_types=1);

namespace BookingEngineConnector\Logging\Migrations;

/**
 * Creates `{wp_prefix}bec_api_log` for outbound API request logging.
 */
final class CreateApiLogTable
{
	public static function up(): void
	{
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'bec_api_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			provider_slug varchar(64) NOT NULL,
			http_method varchar(8) NOT NULL,
			endpoint text NOT NULL,
			status_code smallint NOT NULL,
			duration_ms int NOT NULL,
			error_category varchar(32) NULL,
			message text NULL,
			unit_id bigint(20) unsigned NULL,
			correlation_id varchar(64) NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		dbDelta($sql);
	}
}
