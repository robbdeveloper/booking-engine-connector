<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin;

use BookingEngineConnector\Media\GalleryImageFilenameRenamer;
use BookingEngineConnector\Media\GalleryImageSyncSettings;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Providers\Contracts\ProviderException;
use BookingEngineConnector\Providers\Kross\KrossBookingEngineSyncSettings;
use BookingEngineConnector\Providers\Kross\KrossProvider;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Sync\SyncCron;
use BookingEngineConnector\Sync\SyncProgressReporter;
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
		\add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
		\add_action('wp_ajax_bec_sync_progress_poll', [self::class, 'handleAjaxProgressPoll']);
		\add_action('wp_ajax_bec_sync_run_all', [self::class, 'handleAjaxRunAll']);
		\add_action('admin_post_bec_sync_save_settings', [self::class, 'handleSaveSettings']);
		\add_action('admin_post_bec_kross_refresh_booking_engines', [self::class, 'handleKrossRefreshBookingEngines']);
		\add_action('admin_post_bec_sync_all', [self::class, 'handleSyncAll']);
		\add_action('admin_post_bec_sync_unit', [self::class, 'handleSyncUnit']);
		\add_action('admin_post_bec_rename_gallery_all', [self::class, 'handleRenameGalleryAll']);
		\add_action('admin_post_bec_rename_unit_gallery', [self::class, 'handleRenameUnitGallery']);
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

		$renameResult = \get_transient(self::renameResultTransientKey());
		\delete_transient(self::renameResultTransientKey());

		$krossRefreshResult = \get_transient(self::krossBookingEngineRefreshTransientKey());
		\delete_transient(self::krossBookingEngineRefreshTransientKey());

		$isKrossActive = ProviderRegistry::getActiveSlug() === 'kross';

		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__('Sync units', 'booking-engine-connector') . '</h1>';

		if ($isKrossActive) {
			echo '<form id="bec-kross-booking-engine-refresh" method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '" tabindex="-1" aria-hidden="true" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0;">';
			\wp_nonce_field('bec_kross_refresh_booking_engines', 'bec_kross_refresh_booking_engines_nonce');
			echo '<input type="hidden" name="action" value="bec_kross_refresh_booking_engines" />';
			echo '</form>';
		}

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

		if (\is_array($renameResult)) {
			$scope  = isset($renameResult['scope']) ? (string) $renameResult['scope'] : '';
			$unitId = isset($renameResult['unit_id']) ? (int) $renameResult['unit_id'] : 0;
			echo '<div class="notice notice-info"><p>';
			if ($scope === 'unit' && $unitId > 0) {
				echo \esc_html(
					\sprintf(
						/* translators: 1: unit post ID */
						\__('Gallery file rename for unit #%1$d:', 'booking-engine-connector'),
						$unitId
					)
				);
			} elseif ($scope === 'all') {
				echo \esc_html(
					\sprintf(
						/* translators: 1: number of units processed */
						\__('Gallery file rename across %1$d units:', 'booking-engine-connector'),
						(int) ( $renameResult['units'] ?? 0 )
					)
				);
			} else {
				echo \esc_html__('Gallery file rename:', 'booking-engine-connector');
			}
			echo ' ';
			echo \esc_html(
				\sprintf(
					/* translators: 1: renamed, 2: copied, 3: skipped, 4: failed */
					\__('renamed %1$d, copied %2$d, skipped %3$d, failed %4$d.', 'booking-engine-connector'),
					(int) ( $renameResult['renamed'] ?? 0 ),
					(int) ( $renameResult['duplicated'] ?? 0 ),
					(int) ( $renameResult['skipped'] ?? 0 ),
					(int) ( $renameResult['failed'] ?? 0 )
				)
			);
			if (! empty($renameResult['errors']) && \is_array($renameResult['errors'])) {
				echo ' ' . \esc_html(\implode(' ', \array_map('strval', $renameResult['errors'])));
			}
			echo '</p></div>';
		}

		if (\is_array($krossRefreshResult) && isset($krossRefreshResult['message'])) {
			$noticeClass = (($krossRefreshResult['type'] ?? '') === 'error')
				? 'notice notice-error is-dismissible'
				: 'notice notice-success is-dismissible';

			echo '<div class="' . \esc_attr($noticeClass) . '"><p>' . \esc_html((string) $krossRefreshResult['message']) . '</p></div>';
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

		if ($isKrossActive) {
			$selectedList   = KrossBookingEngineSyncSettings::getSelectedBookingEngines();
			$selectedFlip   = \array_fill_keys($selectedList, true);
			$cachedList     = KrossBookingEngineSyncSettings::getCachedAvailableEngines();
			$displayEngines = \array_unique(\array_merge($cachedList, $selectedList));
			\sort($displayEngines, \SORT_STRING);

			echo '<h2 class="title">' . \esc_html__('Kross booking engines', 'booking-engine-connector') . '</h2>';
			echo '<p class="description">' . \esc_html__(
				'Sync only units whose Kross payload includes the room’s enabled booking-engine slugs (`be_enabled`). Matching any selected slug includes the unit.',
				'booking-engine-connector'
			) . '</p>';
			echo '<p class="description">' . \esc_html__(
				'Leave all unchecked to sync every Kross room type from this property.',
				'booking-engine-connector'
			) . '</p>';

			echo '<table class="form-table" role="presentation">';
			echo '<tr><th scope="row">' . \esc_html__('Booking engines', 'booking-engine-connector') . '</th><td>';

			echo '<p><button type="submit" form="bec-kross-booking-engine-refresh" class="button">' . \esc_html__(
				'Refresh booking engines list from Kross',
				'booking-engine-connector'
			) . '</button>';
			echo ' <span class="description">' . \esc_html__(
				'Merges all `be_enabled` values discovered from `/v5/rooms/get-room-types` into the checklist below.',
				'booking-engine-connector'
			) . '</span></p>';

			if ($displayEngines === []) {
				echo '<p class="description"><em>' . \esc_html__(
					'No engines cached yet — use Refresh to populate this list.',
					'booking-engine-connector'
				) . '</em></p>';
			} else {
				echo '<fieldset class="bec-kross-be-checkboxes"><legend class="screen-reader-text">' . \esc_html__(
					'Kross booking engine slugs to include in sync',
					'booking-engine-connector'
				) . '</legend>';

				foreach ($displayEngines as $engineSlug) {
					if (! \is_string($engineSlug) || $engineSlug === '') {
						continue;
					}
					$inputId = 'bec_kross_sync_be_' . \sanitize_key($engineSlug);

					echo '<p style="margin:0.35em 0;"><label for="' . \esc_attr($inputId) . '">';
					echo '<input type="checkbox" id="' . \esc_attr($inputId) . '" name="bec_kross_sync_booking_engines[]" value="' . \esc_attr($engineSlug) . '" ' .
						\checked(isset($selectedFlip[ $engineSlug ]), true, false) . ' /> ';
					echo '<code>' . \esc_html($engineSlug) . '</code>';
					echo '</label></p>';
				}

				echo '</fieldset>';
			}

			echo '</td></tr></table>';
		}

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

		echo '<hr /><form id="bec-sync-all-form" class="bec-sync-all-form" method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '">';
		\wp_nonce_field('bec_sync_all', 'bec_sync_all_nonce');
		echo '<input type="hidden" name="action" value="bec_sync_all" />';
		\submit_button(\__('Run sync now', 'booking-engine-connector'), 'secondary', '', false, ['id' => 'bec-sync-all-submit']);
		echo '</form>';

		echo '<div id="bec-sync-progress" class="bec-sync-progress" hidden style="margin-top:1em;padding:1em;max-width:56em;border:1px solid #c3c4c7;background:#fff;">';
		echo '<h2 class="title" style="margin-top:0;">' . \esc_html__('Sync progress', 'booking-engine-connector') . '</h2>';
		echo '<p id="bec-sync-progress-status" class="bec-sync-progress__status" style="margin:0.5em 0;font-weight:600;" aria-live="polite"></p>';
		echo '<pre id="bec-sync-progress-log" class="bec-sync-progress__log" style="margin:0;max-height:20em;overflow:auto;white-space:pre-wrap;word-break:break-word;background:#f6f7f7;padding:0.75em;border:1px solid #dcdcde;font-size:12px;line-height:1.45;"></pre>';
		echo '</div>';

		echo '<hr /><h2 class="title">' . \esc_html__('Gallery file names', 'booking-engine-connector') . '</h2>';
		echo '<p class="description">' . \esc_html__(
			'Apply the current filename prefix/suffix settings to images already stored in each unit’s gallery. Images shared by more than one unit are copied for this unit so other units are not affected. This may take a while on large sites.',
			'booking-engine-connector'
		) . '</p>';
		echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '">';
		\wp_nonce_field('bec_rename_gallery_all', 'bec_rename_gallery_all_nonce');
		echo '<input type="hidden" name="action" value="bec_rename_gallery_all" />';
		\submit_button(\__('Rename all unit gallery files', 'booking-engine-connector'), 'secondary');
		echo '</form>';

		echo '</div>';
	}

	public static function enqueueAdminAssets(string $hookSuffix): void
	{
		$onSyncPage = isset($_GET['page']) && (string) \sanitize_key(\wp_unslash((string) $_GET['page'])) === self::PAGE_SLUG;
		$onHook     = $hookSuffix === 'bec-dashboard_page_' . self::PAGE_SLUG;
		if (! $onSyncPage && ! $onHook) {
			return;
		}

		\wp_enqueue_script(
			'bec-admin-sync-progress',
			\BEC_PLUGIN_URL . 'assets/admin-sync-progress.js',
			[],
			\BEC_VERSION,
			true
		);
		\wp_localize_script(
			'bec-admin-sync-progress',
			'becSyncProgress',
			[
				'ajaxUrl'   => \admin_url('admin-ajax.php'),
				'nonce'     => \wp_create_nonce('bec_sync_progress'),
				'syncNonce' => \wp_create_nonce('bec_sync_all'),
				'syncFailedGeneric' => \__(
					'Sync failed.',
					'booking-engine-connector'
				),
				/* translators: 1: created posts, 2: updated posts, 3: skipped posts */
				'syncResultSummary' => \__(
					'Created %1$d, updated %2$d, skipped %3$d.',
					'booking-engine-connector'
				),
				'syncUnexpectedResponse' => \__(
					'Sync request failed or returned an unexpected response. Try again or disable JavaScript to use the standard sync.',
					'booking-engine-connector'
				),
			]
		);
	}

	public static function handleAjaxProgressPoll(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_send_json_error(
				[ 'message' => \__('Insufficient permissions.', 'booking-engine-connector') ],
				403
			);
		}

		\check_ajax_referer('bec_sync_progress', 'nonce');

		$runId = isset($_REQUEST['run_id']) ? \wp_unslash((string) $_REQUEST['run_id']) : '';
		if (! SyncProgressReporter::isValidRunId($runId)) {
			\wp_send_json_success(
				[
					'status'  => 'invalid',
					'message' => '',
					'lines'   => [],
					'current' => 0,
					'total'   => 0,
				]
			);
		}

		$data = SyncProgressReporter::read((int) \get_current_user_id(), $runId);
		if ($data === null) {
			\wp_send_json_success(
				[
					'status'  => 'waiting',
					'message' => \__('Waiting for sync to start…', 'booking-engine-connector'),
					'lines'   => [],
					'current' => 0,
					'total'   => 0,
				]
			);
		}

		\wp_send_json_success($data);
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

		if (ProviderRegistry::getActiveSlug() === 'kross') {
			$rawEngines = [];

			if (
				isset($_POST['bec_kross_sync_booking_engines'])
				&& \is_array($_POST['bec_kross_sync_booking_engines'])
			) {
				$unslash = \wp_unslash($_POST['bec_kross_sync_booking_engines']);

				foreach ($unslash as $slug) {
					if (\is_string($slug) || \is_numeric($slug)) {
						$rawEngines[] = (string) $slug;
					}
				}
			}

			KrossBookingEngineSyncSettings::setSelectedBookingEngines($rawEngines);
		}

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

		$renameUrl                = \wp_nonce_url(
			\admin_url('admin-post.php?action=bec_rename_unit_gallery&post_id=' . (int) $post->ID),
			'bec_rename_unit_gallery_' . (int) $post->ID
		);
		$actions['bec_rename_gallery'] = '<a href="' . \esc_url($renameUrl) . '">' . \esc_html__('Rename gallery files', 'booking-engine-connector') . '</a>';

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

	public static function handleAjaxRunAll(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_send_json_error(
				[ 'message' => \__('Insufficient permissions.', 'booking-engine-connector') ],
				403
			);
		}

		\check_ajax_referer('bec_sync_all', 'sync_nonce');

		try {
			$result = self::executeFullSyncAndStoreResult();
		} catch (\Throwable $e) {
			$message = self::formatUnexpectedSyncFailureMessage($e);
			$progress = self::progressFromRequest();
			if ($progress !== null) {
				$progress->fail($message);
			}

			\wp_send_json_error([ 'message' => $message ], 500);
			return;
		}

		\wp_send_json_success(['result' => $result]);
	}

	public static function handleSyncAll(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_die(\esc_html__('Insufficient permissions.', 'booking-engine-connector'));
		}

		\check_admin_referer('bec_sync_all', 'bec_sync_all_nonce');

		self::executeFullSyncAndStoreResult();

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

	public static function handleRenameGalleryAll(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_die(\esc_html__('Insufficient permissions.', 'booking-engine-connector'));
		}

		\check_admin_referer('bec_rename_gallery_all', 'bec_rename_gallery_all_nonce');

		$result  = GalleryImageFilenameRenamer::renameForAllUnits();
		$payload = \array_merge(['scope' => 'all'], $result);
		\set_transient(self::renameResultTransientKey(), $payload, 120);

		\wp_safe_redirect(\admin_url('admin.php?page=' . self::PAGE_SLUG));
		exit;
	}

	public static function handleRenameUnitGallery(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_die(\esc_html__('Insufficient permissions.', 'booking-engine-connector'));
		}

		$postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
		\check_admin_referer('bec_rename_unit_gallery_' . $postId);

		$r       = GalleryImageFilenameRenamer::renameForUnit($postId);
		$payload = \array_merge(
			[
				'scope'   => 'unit',
				'unit_id' => $postId,
			],
			$r
		);
		\set_transient(self::renameResultTransientKey(), $payload, 120);

		\wp_safe_redirect(\admin_url('admin.php?page=' . self::PAGE_SLUG));
		exit;
	}

	public static function handleKrossRefreshBookingEngines(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_die(\esc_html__('Insufficient permissions.', 'booking-engine-connector'));
		}

		\check_admin_referer('bec_kross_refresh_booking_engines', 'bec_kross_refresh_booking_engines_nonce');

		if (ProviderRegistry::getActiveSlug() !== 'kross') {
			\set_transient(
				self::krossBookingEngineRefreshTransientKey(),
				[
					'type'    => 'error',
					'message' => \__(
						'Booking engine discovery is available only while Kross is the active provider.',
						'booking-engine-connector'
					),
				],
				120
			);

			self::redirectToSyncSettingsPage();

			exit;
		}

		$resolved = ProviderRegistry::getProvider();
		if (! $resolved->validateCredentials()) {
			\set_transient(
				self::krossBookingEngineRefreshTransientKey(),
				[
					'type'    => 'error',
					'message' => \__(
						'Complete Kross credentials on the Connection page before refreshing booking engines.',
						'booking-engine-connector'
					),
				],
				120
			);

			self::redirectToSyncSettingsPage();

			exit;
		}

		/** @var KrossProvider $krossProvider */
		$krossProvider = $resolved instanceof KrossProvider ? $resolved : new KrossProvider();

		try {
			$krossProvider->refreshBookingEngineOptionsFromRemote();
			$count = \count(KrossBookingEngineSyncSettings::getCachedAvailableEngines());

			\set_transient(
				self::krossBookingEngineRefreshTransientKey(),
				[
					'type'    => 'success',
					'message' => \sprintf(
						/* translators: %d number of distinct booking-engine slugs cached after refresh */
						\_n(
							'Booking engines list refreshed. %d slug cached.',
							'Booking engines list refreshed. %d slugs cached.',
							$count,
							'booking-engine-connector'
						),
						$count
					),
				],
				120
			);
		} catch (ProviderException $e) {
			self::persistKrossBookingEngineTransientErrorFromThrowable($e);
		} catch (\Throwable $e) {
			self::persistKrossBookingEngineTransientErrorFromThrowable($e);
		}

		self::redirectToSyncSettingsPage();

		exit;
	}

	private static function persistKrossBookingEngineTransientErrorFromThrowable(\Throwable $e): void
	{
		\set_transient(
			self::krossBookingEngineRefreshTransientKey(),
			[
				'type'    => 'error',
				'message' => \sprintf(
					/* translators: %s error detail */
					\__('Could not refresh booking engines: %s', 'booking-engine-connector'),
					$e->getMessage()
				),
			],
			120
		);
	}

	private static function redirectToSyncSettingsPage(): void
	{
		\wp_safe_redirect(\admin_url('admin.php?page=' . self::PAGE_SLUG));
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

	/**
	 * @return array{created:int, updated:int, skipped:int, errors: list<string>}
	 */
	private static function executeFullSyncAndStoreResult(): array
	{
		self::prepareLongRunningSync();

		$progress = self::progressFromRequest();
		$service = new SyncService();
		$result  = $service->syncAll($progress);
		\set_transient(self::resultTransientKey(), $result, 120);

		return $result;
	}

	private static function prepareLongRunningSync(): void
	{
		if (\function_exists('wp_raise_memory_limit')) {
			\wp_raise_memory_limit('admin');
		}

		if (\function_exists('ignore_user_abort')) {
			@\ignore_user_abort(true);
		}

		if (\function_exists('set_time_limit')) {
			@\set_time_limit(0);
		}
	}

	private static function progressFromRequest(): ?SyncProgressReporter
	{
		$runRaw = isset($_POST['bec_sync_run_id']) ? (string) \wp_unslash((string) $_POST['bec_sync_run_id']) : '';
		if (! SyncProgressReporter::isValidRunId($runRaw)) {
			return null;
		}

		return new SyncProgressReporter((int) \get_current_user_id(), $runRaw);
	}

	private static function formatUnexpectedSyncFailureMessage(\Throwable $e): string
	{
		return \sprintf(
			/* translators: %s error detail */
			\__('Sync failed unexpectedly: %s', 'booking-engine-connector'),
			$e->getMessage()
		);
	}

	private static function resultTransientKey(): string
	{
		return 'bec_sync_result_' . \get_current_user_id();
	}

	private static function renameResultTransientKey(): string
	{
		return 'bec_rename_gallery_result_' . \get_current_user_id();
	}

	private static function krossBookingEngineRefreshTransientKey(): string
	{
		return 'bec_kross_booking_engine_refresh_notice_' . \get_current_user_id();
	}
}
