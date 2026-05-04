<?php
/**
 * Uninstall handler — runs when the plugin is deleted from the admin.
 *
 * @package BookingEngineConnector
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

if (! defined('BEC_UNINSTALL_DELETE_DATA') || ! BEC_UNINSTALL_DELETE_DATA) {
	return;
}

global $wpdb;

$options = [
	'bec_db_version',
	'bec_active_provider',
	'bec_kross_hotel_id',
	'bec_kross_api_key',
	'bec_kross_username',
	'bec_kross_password',
	'bec_sync_interval_hours',
	'bec_sync_last_run_at',
	'bec_kross_checkout_base_url',
	'bec_kross_checkout_http_method',
	'bec_fallback_enabled',
	'bec_fallback_mode',
	'bec_fallback_force',
	'bec_fallback_trigger_categories',
	'bec_fallback_empty_quote',
	'bec_fallback_link_url',
	'bec_fallback_link_text',
	'bec_fallback_inline_content',
	'bec_styling_search_preset',
	'bec_styling_booking_summary_preset',
	'bec_styling_theme_variables_css',
	'bec_styling_search_extra_css',
	'bec_styling_summary_extra_css',
	'bec_styling_accordion_inclusions',
	'bec_styling_accordion_conditions',
];

foreach ($options as $option) {
	delete_option($option);
}

$table = $wpdb->prefix . 'bec_api_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix.
$wpdb->query("DROP TABLE IF EXISTS {$table}");

delete_transient('bec_kross_access_token');
delete_transient('bec_kross_access_token_exp');
delete_transient('bec_kross_token_lock');
