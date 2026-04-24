<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin;

use BookingEngineConnector\Media\GalleryImageSyncSettings;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Sync\SyncCron;
use BookingEngineConnector\Sync\SyncService;

/**
 * Sync settings page, bulk sync, row action, admin_post handlers.
 */
final class SyncAdmin
{
	public const PAGE_SLUG = 'bec-sync';

	public static function register(): void
	{
		\add_action('admin_init', [self::class, 'registerListHooks']);
		\add_action('admin_post_bec_sync_save_settings', [self::class, 'handleSaveSettings']);
		\add_action('admin_post_bec_sync_all', [self::class, 'handleSyncAll']);
		\add_action('admin_post_bec_sync_unit', [self::class, 'handleSyncUnit']);
		\add_action('admin_notices', [self::class, 'renderNotices']);
	}

	public static function renderPage(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$hours = \max(1, (int) \get_option(SyncCron::OPTION_INTERVAL_HOURS, 6));
		$last  = (string) \get_option('bec_sync_last_run_at', '');

		$imgPrefix = GalleryImageSyncSettings::getFilenamePrefix();
		$imgSuffix = GalleryImageSyncSettings::getFilenameSuffix();

		$result = \get_transient(self::resultTransientKey());
		\delete_transient(self::resultTransientKey());

		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__('Sync units', 'booking-engine-connector') . '</h1>';

		if (\is_array($result)) {
			echo '<div class="notice notice-info"><p>';
			echo \esc_html(
				\sprintf(
					/* translators: 1: created count, 2: updated, 3: skipped */
					\__('Last run: created %1$d, updated %2$d, skipped %3$d.', 'booking-engine-connector'),
					(int) ($result['created'] ?? 0),
					(int) ($result['updated'] ?? 0),
					(int) ($result['skipped'] ?? 0)
				)
			);
			if (! empty($result['errors']) && \is_array($result['errors'])) {
				echo ' ' . \esc_html(\implode(' ', \array_map('strval', $result['errors'])));
			}
			echo '</p></div>';
		}

		if ($last !== '') {
			echo '<p>' . \esc_html(\sprintf(
				/* translators: %s datetime */
				\__('Last successful sync completion: %s', 'booking-engine-connector'),
				$last
			)) . '</p>';
		}

		echo '<p class="description">' . \esc_html__('Scheduled sync uses WP-Cron; on low-traffic sites runs may be delayed until the next page view.', 'booking-engine-connector') . '</p>';

		echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '">';
		\wp_nonce_field('bec_sync_settings', 'bec_sync_settings_nonce');
		echo '<input type="hidden" name="action" value="bec_sync_save_settings" />';
		echo '<h2 class="title">' . \esc_html__('Schedule', 'booking-engine-connector') . '</h2>';
		echo '<table class="form-table"><tr><th><label for="bec_sync_interval_hours">' . \esc_html__('Interval (hours)', 'booking-engine-connector') . '</label></th><td>';
		echo '<input type="number" min="1" max="168" name="bec_sync_interval_hours" id="bec_sync_interval_hours" value="' . \esc_attr((string) $hours) . '" />';
		echo '<p class="description">' . \esc_html__('How often the scheduled sync runs (1–168).', 'booking-engine-connector') . '</p>';
		echo '</td></tr></table>';

		echo '<h2 class="title">' . \esc_html__('Gallery image filenames', 'booking-engine-connector') . '</h2>';
		echo '<p class="description">' . \esc_html__(
			'Synced unit images are saved with: prefix + unit name (slug) + suffix + order index + file extension. Leave prefix and suffix empty to use only the unit name.',
			'booking-engine-connector'
		) . '</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="bec_sync_gallery_image_prefix">' . \esc_html__('Filename prefix', 'booking-engine-connector') . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="bec_sync_gallery_image_prefix" id="bec_sync_gallery_image_prefix" value="' . \esc_attr($imgPrefix) . '" autocomplete="off" />';
		echo '<p class="description">' . \esc_html__('Optional text prepended to each file base name (safe characters only).', 'booking-engine-connector') . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="bec_sync_gallery_image_suffix">' . \esc_html__('Filename suffix (before index)', 'booking-engine-connector') . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="bec_sync_gallery_image_suffix" id="bec_sync_gallery_image_suffix" value="' . \esc_attr($imgSuffix) . '" autocomplete="off" />';
		echo '<p class="description">' . \esc_html__(
			'Placed after the unit name slug, before the numeric index (e.g. "-room").',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr></table>';

		\submit_button(\__('Save sync settings', 'booking-engine-connector'));
		echo '</form>';

		echo '<hr /><form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '">';
		\wp_nonce_field('bec_sync_all', 'bec_sync_all_nonce');
		echo '<input type="hidden" name="action" value="bec_sync_all" />';
		\submit_button(\__('Run sync now', 'booking-engine-connector'), 'secondary');
		echo '</form>';

		echo '</div>';
	}

	public static function registerListHooks(): void
	{
		$slug = UnitPostType::getSlug();

		\add_filter('post_row_actions', [self::class, 'filterRowActions'], 10, 2);
		\add_filter("bulk_actions-edit-{$slug}", [self::class, 'registerBulkAction']);
		\add_filter("handle_bulk_actions-edit-{$slug}", [self::class, 'handleBulk'], 10, 3);
	}

	public static function handleSaveSettings(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_die(\esc_html__('Insufficient permissions.', 'booking-engine-connector'));
		}

		\check_admin_referer('bec_sync_settings', 'bec_sync_settings_nonce');

		$h = isset($_POST['bec_sync_interval_hours']) ? (int) $_POST['bec_sync_interval_hours'] : 6;
		$h = \max(1, \min(168, $h));
		\update_option(SyncCron::OPTION_INTERVAL_HOURS, $h, false);
		SyncCron::reschedule();

		$prefix = isset($_POST['bec_sync_gallery_image_prefix']) ? \wp_unslash((string) $_POST['bec_sync_gallery_image_prefix']) : '';
		$suffix = isset($_POST['bec_sync_gallery_image_suffix']) ? \wp_unslash((string) $_POST['bec_sync_gallery_image_suffix']) : '';
		\update_option(GalleryImageSyncSettings::OPTION_FILENAME_PREFIX, \sanitize_file_name($prefix), false);
		\update_option(GalleryImageSyncSettings::OPTION_FILENAME_SUFFIX, \sanitize_file_name($suffix), false);

		\wp_safe_redirect(\add_query_arg(['page' => self::PAGE_SLUG, 'bec_saved' => '1'], \admin_url('admin.php')));
		exit;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function filterRowActions(array $actions, \WP_Post $post): array
	{
		if ($post->post_type !== UnitPostType::getSlug()) {
			return $actions;
		}

		$url                = \wp_nonce_url(
			\admin_url('admin-post.php?action=bec_sync_unit&post_id=' . (int) $post->ID),
			'bec_sync_unit_' . (int) $post->ID
		);
		$actions['bec_sync'] = '<a href="' . \esc_url($url) . '">' . \esc_html__('Sync now', 'booking-engine-connector') . '</a>';

		return $actions;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function registerBulkAction(array $actions): array
	{
		$actions['bec_sync_bulk'] = \__('Sync with provider', 'booking-engine-connector');

		return $actions;
	}

	/**
	 * @param list<int> $post_ids
	 */
	public static function handleBulk(string $redirect, string $action, array $post_ids): string
	{
		if ($action !== 'bec_sync_bulk') {
			return $redirect;
		}

		$service = new SyncService();
		$errors  = 0;

		foreach ($post_ids as $pid) {
			$pid = (int) $pid;
			if ($pid < 1) {
				continue;
			}
			try {
				$service->syncPost($pid);
			} catch (\Throwable $e) {
				++$errors;
			}
		}

		return \add_query_arg('bec_bulk_synced', $errors === 0 ? '1' : '0', $redirect);
	}

	public static function handleSyncAll(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_die(\esc_html__('Insufficient permissions.', 'booking-engine-connector'));
		}

		\check_admin_referer('bec_sync_all', 'bec_sync_all_nonce');

		$service = new SyncService();
		$result  = $service->syncAll();
		\set_transient(self::resultTransientKey(), $result, 120);

		\wp_safe_redirect(\admin_url('admin.php?page=' . self::PAGE_SLUG . '&bec_sync_done=1'));
		exit;
	}

	public static function handleSyncUnit(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_die(\esc_html__('Insufficient permissions.', 'booking-engine-connector'));
		}

		$postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
		\check_admin_referer('bec_sync_unit_' . $postId);

		try {
			( new SyncService() )->syncPost($postId);
			$ok = true;
		} catch (\Throwable $e) {
			$ok = false;
			\set_transient(self::resultTransientKey(), ['errors' => [$e->getMessage()]], 120);
		}

		$url = \add_query_arg(
			[
				'post_type' => UnitPostType::getSlug(),
				'bec_unit'  => $ok ? 'synced' : 'sync_fail',
			],
			\admin_url('edit.php')
		);

		\wp_safe_redirect($url);
		exit;
	}

	public static function renderNotices(): void
	{
		if (! \is_admin() || ! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		if (isset($_GET['page']) && (string) \sanitize_key(\wp_unslash((string) $_GET['page'])) === self::PAGE_SLUG && isset($_GET['bec_saved'])) {
			if ((string) \sanitize_text_field(\wp_unslash((string) $_GET['bec_saved'])) === '1') {
				echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html__('Sync settings saved.', 'booking-engine-connector') . '</p></div>';
			}
		}
	}

	private static function resultTransientKey(): string
	{
		return 'bec_sync_result_' . \get_current_user_id();
	}
}
