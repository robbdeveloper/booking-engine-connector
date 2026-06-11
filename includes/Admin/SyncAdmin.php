<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin;

use BookingEngineConnector\Media\GalleryImageFilenameRenamer;
use BookingEngineConnector\Media\GalleryImageSyncSettings;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Media\RemoteGalleryImporter;
use BookingEngineConnector\Providers\Contracts\ProviderException;
use BookingEngineConnector\Providers\Kross\KrossBookingEngineSyncSettings;
use BookingEngineConnector\Providers\Kross\KrossProvider;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Sync\CoreUnitFieldRegistry;
use BookingEngineConnector\Sync\SyncCron;
use BookingEngineConnector\Sync\SyncManualBatchState;
use BookingEngineConnector\Sync\SyncLock;
use BookingEngineConnector\Sync\SyncProgressReporter;
use BookingEngineConnector\Sync\SyncService;
use BookingEngineConnector\Units\CoreUnitMetaKeys;
use BookingEngineConnector\Units\CoreUnitSemantic;
use BookingEngineConnector\Admin\AdminPageLayout;

/**
 * Sync settings page, bulk sync, row action, admin_post handlers.
 */
final class SyncAdmin
{
	public const PAGE_SLUG = 'bec-sync';

	public const TAB_SETTINGS = 'settings';

	public const TAB_TOOLS = 'tools';

	/**
	 * @param array<string, scalar|null> $extra
	 */
	public static function adminPageUrl(string $tab = self::TAB_SETTINGS, array $extra = []): string
	{
		$args = \array_merge(['page' => self::PAGE_SLUG], $extra);
		if ($tab === self::TAB_TOOLS) {
			$args['tab'] = self::TAB_TOOLS;
		}

		return \add_query_arg($args, \admin_url('admin.php'));
	}

	public static function register(): void
	{
		\add_action('admin_init', [self::class, 'registerListHooks']);
		\add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
		\add_action('wp_ajax_bec_sync_progress_poll', [self::class, 'handleAjaxProgressPoll']);
		\add_action('wp_ajax_bec_sync_run_all', [self::class, 'handleAjaxRunAll']);
		\add_action('wp_ajax_bec_sync_start_all', [self::class, 'handleAjaxStartAll']);
		\add_action('wp_ajax_bec_sync_step_all', [self::class, 'handleAjaxStepAll']);
		\add_action('admin_post_bec_sync_save_settings', [self::class, 'handleSaveSettings']);
		\add_action('admin_post_bec_kross_refresh_booking_engines', [self::class, 'handleKrossRefreshBookingEngines']);
		\add_action('admin_post_bec_sync_all', [self::class, 'handleSyncAll']);
		\add_action('admin_post_bec_sync_clear_running_lock', [self::class, 'handleClearRunningLock']);
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

		AdminPageLayout::wrapOpen(
			\__('Sync & Import', 'booking-engine-connector'),
			\__(
				'Schedule automatic syncs, filter Kross booking engines, run manual imports, and manage gallery filenames.',
				'booking-engine-connector'
			),
			'bec-sync-admin'
		);

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

		$activeTab = self::resolveActiveTab();

		AdminPageLayout::tabsNavOpen(
			\__('Sync & Import sections', 'booking-engine-connector')
		);
		AdminPageLayout::tabLink(
			self::adminPageUrl(self::TAB_SETTINGS),
			\__('Settings', 'booking-engine-connector'),
			$activeTab === self::TAB_SETTINGS
		);
		AdminPageLayout::tabLink(
			self::adminPageUrl(self::TAB_TOOLS),
			\__('Tools', 'booking-engine-connector'),
			$activeTab === self::TAB_TOOLS
		);
		AdminPageLayout::tabsNavClose();

		AdminPageLayout::tabPanelOpen('bec-sync-tab-settings', $activeTab === self::TAB_SETTINGS);

		echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '">';
		\wp_nonce_field('bec_sync_settings', 'bec_sync_settings_nonce');
		echo '<input type="hidden" name="action" value="bec_sync_save_settings" />';

		AdminPageLayout::cardOpen(
			\__('Schedule', 'booking-engine-connector'),
			\__(
				'Scheduled sync uses WP-Cron; on low-traffic sites runs may be delayed until the next page view.',
				'booking-engine-connector'
			)
		);

		echo '<table class="form-table"><tr><th><label for="bec_sync_interval_hours">' . \esc_html__('Interval (hours)', 'booking-engine-connector') . '</label></th><td>';
		echo '<input type="number" min="1" max="168" name="bec_sync_interval_hours" id="bec_sync_interval_hours" value="' . \esc_attr((string) $hours) . '" />';
		echo '<p class="description">' . \esc_html__('How often the scheduled sync runs (1–168).', 'booking-engine-connector') . '</p>';
		echo '</td></tr></table>';
		AdminPageLayout::cardClose();

		if ($isKrossActive) {
			$selectedList   = KrossBookingEngineSyncSettings::getSelectedBookingEngines();
			$selectedFlip   = \array_fill_keys($selectedList, true);
			$cachedList     = KrossBookingEngineSyncSettings::getCachedAvailableEngines();
			$displayEngines = \array_unique(\array_merge($cachedList, $selectedList));
			\sort($displayEngines, \SORT_STRING);

			AdminPageLayout::cardOpen(
				\__('Kross booking engines', 'booking-engine-connector'),
				\esc_html__(
					'Sync only units whose Kross payload includes the room’s enabled booking-engine slugs (`be_enabled`). Matching any selected slug includes the unit. Leave all unchecked to sync every Kross room type from this property.',
					'booking-engine-connector'
				)
			);

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
			AdminPageLayout::cardClose();
		}

		AdminPageLayout::cardOpen(
			\__('Gallery image filenames', 'booking-engine-connector'),
			\esc_html__(
				'Synced unit images are saved with: prefix + unit name (slug) + suffix + order index + file extension. Leave prefix and suffix empty to use only the unit name.',
				'booking-engine-connector'
			)
		);
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
		AdminPageLayout::cardClose();

		\submit_button(\__('Save sync settings', 'booking-engine-connector'));
		echo '</form>';

		AdminPageLayout::tabPanelClose();

		AdminPageLayout::tabPanelOpen('bec-sync-tab-tools', $activeTab === self::TAB_TOOLS);

		if ($last !== '') {
			echo '<p class="description">' . \esc_html(\sprintf(
				/* translators: %s datetime */
				\__('Last successful sync completion: %s', 'booking-engine-connector'),
				$last
			)) . '</p>';
		}

		AdminPageLayout::cardOpen(
			\__('Run sync', 'booking-engine-connector'),
			\esc_html__(
				'Import or update all units from the active provider. JavaScript shows live progress; without JS the standard admin-post fallback still runs.',
				'booking-engine-connector'
			)
		);
		echo '<form id="bec-sync-all-form" class="bec-sync-all-form" method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '">';
		\wp_nonce_field('bec_sync_all', 'bec_sync_all_nonce');
		echo '<input type="hidden" name="action" value="bec_sync_all" />';
		\submit_button(\__('Run sync now', 'booking-engine-connector'), 'secondary', '', false, ['id' => 'bec-sync-all-submit']);
		echo '</form>';

		echo '<div id="bec-sync-progress" class="bec-sync-progress" hidden style="margin-top:1em;padding:1em;max-width:56em;border:1px solid #c3c4c7;background:#fff;">';
		echo '<h2 class="title" style="margin-top:0;">' . \esc_html__('Sync progress', 'booking-engine-connector') . '</h2>';
		echo '<p id="bec-sync-progress-status" class="bec-sync-progress__status" style="margin:0.5em 0;font-weight:600;" aria-live="polite"></p>';
		echo '<pre id="bec-sync-progress-log" class="bec-sync-progress__log" style="margin:0;max-height:20em;overflow:auto;white-space:pre-wrap;word-break:break-word;background:#f6f7f7;padding:0.75em;border:1px solid #dcdcde;font-size:12px;line-height:1.45;"></pre>';
		echo '</div>';
		AdminPageLayout::cardClose();

		AdminPageLayout::cardOpen(
			\__('Sync lock', 'booking-engine-connector'),
			\esc_html__(
				'The sync lock prevents overlapping full syncs. If a run was interrupted you may see “Another sync is already running” until the lock expires or you clear it here.',
				'booking-engine-connector'
			)
		);
		$locked = SyncLock::isLocked();
		echo '<p><strong>' . \esc_html__('Current status:', 'booking-engine-connector') . '</strong> ';
		echo $locked
			? \esc_html__('Lock is set (a sync may be in progress or stale).', 'booking-engine-connector')
			: \esc_html__('No lock is set.', 'booking-engine-connector');
		echo '</p>';
		echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '" id="bec-sync-clear-lock-form" onsubmit="return window.confirm(\'' . \esc_js(
			\__(
				'Clear the sync lock? Only do this if no sync is running. Active syncs may be affected.',
				'booking-engine-connector'
			)
		) . '\');">';
		\wp_nonce_field('bec_sync_clear_running_lock', 'bec_sync_clear_running_lock_nonce');
		echo '<input type="hidden" name="action" value="bec_sync_clear_running_lock" />';
		\submit_button(\__('Clear sync lock', 'booking-engine-connector'), 'secondary', 'submit', false, [
			'id'    => 'bec-sync-clear-lock-submit',
			'style' => 'border-color:#b32d2e;color:#b32d2e;',
		]);
		echo '</form>';
		AdminPageLayout::cardClose();

		AdminPageLayout::cardOpen(
			\__('Gallery file rename', 'booking-engine-connector'),
			\esc_html__(
				'Apply the current filename prefix/suffix settings to images already stored in each unit’s gallery. Images shared by more than one unit are copied for this unit so other units are not affected. This may take a while on large sites.',
				'booking-engine-connector'
			)
		);
		echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '">';
		\wp_nonce_field('bec_rename_gallery_all', 'bec_rename_gallery_all_nonce');
		echo '<input type="hidden" name="action" value="bec_rename_gallery_all" />';
		\submit_button(\__('Rename all unit gallery files', 'booking-engine-connector'), 'secondary');
		echo '</form>';
		AdminPageLayout::cardClose();

		AdminPageLayout::tabPanelClose();

		AdminPageLayout::wrapClose();
	}

	private static function resolveActiveTab(): string
	{
		if (isset($_GET['bec_sync_done']) || isset($_GET['bec_sync_lock_cleared'])) {
			return self::TAB_TOOLS;
		}

		if (isset($_GET['bec_saved'])) {
			return self::TAB_SETTINGS;
		}

		if (isset($_GET['tab'])) {
			$tab = \sanitize_key(\wp_unslash((string) $_GET['tab']));
			if ($tab === self::TAB_TOOLS || $tab === self::TAB_SETTINGS) {
				return $tab;
			}
		}

		return self::TAB_SETTINGS;
	}

	/**
	 * @param array<string, scalar|null> $query
	 */
	private static function redirectToSyncPage(array $query = []): void
	{
		$tab = self::TAB_SETTINGS;
		if (isset($query['tab'])) {
			$tab = \sanitize_key((string) $query['tab']);
			unset($query['tab']);
		}

		if ($tab !== self::TAB_TOOLS && $tab !== self::TAB_SETTINGS) {
			$tab = self::TAB_SETTINGS;
		}

		\wp_safe_redirect(self::adminPageUrl($tab, $query));
		exit;
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

	public static function handleAjaxStartAll(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_send_json_error(
				[ 'message' => \__('Insufficient permissions.', 'booking-engine-connector') ],
				403
			);
		}

		\check_ajax_referer('bec_sync_all', 'sync_nonce');

		self::prepareLongRunningSync();

		$runRaw = isset($_POST['bec_sync_run_id']) ? (string) \wp_unslash((string) $_POST['bec_sync_run_id']) : '';
		if (! SyncProgressReporter::isValidRunId($runRaw)) {
			\wp_send_json_error(
				[ 'message' => \__('Invalid sync run id.', 'booking-engine-connector') ],
				400
			);
		}

		$uid = (int) \get_current_user_id();
		$san = SyncProgressReporter::sanitizeRunId($runRaw);

		if (! SyncLock::acquireManual($uid, $san)) {
			\wp_send_json_error(
				[ 'message' => \__('Another sync is already running.', 'booking-engine-connector') ],
				409
			);
		}

		$progress = new SyncProgressReporter($uid, $runRaw);
		$progress->running(\__('Starting sync…', 'booking-engine-connector'));
		$progress->addLine(
			\__(
				'Preparing batched sync (each step runs briefly so the browser does not time out).',
				'booking-engine-connector'
			)
		);

		$provider = ProviderRegistry::getProvider();
		if (! $provider->validateCredentials()) {
			SyncLock::releaseManual($uid, $san);
			$msg = \__('Provider credentials are incomplete.', 'booking-engine-connector');
			$progress->addLine($msg);
			$progress->fail($msg);
			\wp_send_json_error([ 'message' => $msg ], 400);
		}

		$slug = $provider->getSlug();

		$remote = [];
		try {
			$remote = $provider->fetchRemoteUnits();
		} catch (ProviderException $e) {
			SyncLock::releaseManual($uid, $san);
			$progress->fail($e->getMessage());
			\wp_send_json_error([ 'message' => $e->getMessage() ], 502);
		} catch (\Throwable $e) {
			SyncLock::releaseManual($uid, $san);
			$m = self::formatUnexpectedSyncFailureMessage($e);
			$progress->fail($m);
			\wp_send_json_error([ 'message' => $m ], 500);
		}

		$service = new SyncService();
		$rows    = $service->normalizeRemoteUnitRows($remote);
		$total   = \count($rows);

		$state = [
			'provider_slug'  => $slug,
			'run_id'         => $san,
			'rows'           => $rows,
			'row_cursor'     => 0,
			'created'        => 0,
			'updated'        => 0,
			'skipped'        => 0,
			'errors'         => [],
			'gallery_queue'  => [],
			'active_gallery' => null,
			'started_at'     => \time(),
		];

		SyncManualBatchState::set($uid, $runRaw, $state);

		$progress->setCounters(0, $total);
		if ($total === 0) {
			$progress->addLine(\__('No remote unit rows returned.', 'booking-engine-connector'));
		} else {
			$progress->addLine(
				\sprintf(
					/* translators: %d: number of remote rows to process */
					\_n('%d remote unit row to process.', '%d remote unit rows to process.', $total, 'booking-engine-connector'),
					$total
				)
			);
		}

		\wp_send_json_success(
			[
				'run_id' => $runRaw,
				'total'  => $total,
			]
		);
	}

	public static function handleAjaxStepAll(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_send_json_error(
				[ 'message' => \__('Insufficient permissions.', 'booking-engine-connector') ],
				403
			);
		}

		\check_ajax_referer('bec_sync_all', 'sync_nonce');

		$runRaw = isset($_POST['bec_sync_run_id']) ? (string) \wp_unslash((string) $_POST['bec_sync_run_id']) : '';
		if (! SyncProgressReporter::isValidRunId($runRaw)) {
			\wp_send_json_error(
				[ 'message' => \__('Invalid sync run id.', 'booking-engine-connector') ],
				400
			);
		}

		$uid = (int) \get_current_user_id();
		$san = SyncProgressReporter::sanitizeRunId($runRaw);

		if (! SyncLock::isHeldByManualRun($uid, $san)) {
			\wp_send_json_error(
				[
					'message' => \__(
						'Sync lock was lost or this run expired. Start a new sync.',
						'booking-engine-connector'
					),
				],
				409
			);
		}

		SyncLock::refresh();

		$state = SyncManualBatchState::get($uid, $runRaw);
		if ($state === null) {
			SyncLock::releaseManual($uid, $san);
			\wp_send_json_error(
				[
					'message' => \__(
						'Sync batch state expired or was cleared. Start a new sync.',
						'booking-engine-connector'
					),
				],
				410
			);
		}

		$progress = new SyncProgressReporter($uid, $runRaw);
		$service  = new SyncService();
		$slug     = (string) ( $state['provider_slug'] ?? '' );

		$galleryBatch = (int) \apply_filters('bec_sync_manual_gallery_batch_size', 4, $uid, $runRaw);
		if ($galleryBatch < 1) {
			$galleryBatch = 1;
		}

		if (! isset($state['gallery_queue']) || ! \is_array($state['gallery_queue'])) {
			$state['gallery_queue'] = [];
		}

		if (($state['active_gallery'] ?? null) === null) {
			/** @var list<array<string, mixed>> $gq */
			$gq = isset($state['gallery_queue']) && \is_array($state['gallery_queue']) ? $state['gallery_queue'] : [];
			if ($gq !== []) {
				$job                     = \array_shift($gq);
				$state['gallery_queue'] = $gq;
				$postId                  = (int) ( $job['post_id'] ?? 0 );
				$payload                 = isset($job['payload']) && \is_array($job['payload']) ? $job['payload'] : [];
				if ($postId > 0 && $payload !== []) {
					$state['active_gallery'] = [
						'post_id' => $postId,
						'payload' => $payload,
						'resume'  => null,
					];
					$progress->addLine(
						\sprintf(
							/* translators: %d: WordPress post ID */
							\__('Starting deferred gallery import for unit #%d…', 'booking-engine-connector'),
							$postId
						)
					);
				}
			}
		}

		if (($state['active_gallery'] ?? null) !== null && \is_array($state['active_gallery'])) {
			$ag      = $state['active_gallery'];
			$postId  = (int) ( $ag['post_id'] ?? 0 );
			$payload = isset($ag['payload']) && \is_array($ag['payload']) ? $ag['payload'] : [];
			$resume  = isset($ag['resume']) && \is_array($ag['resume']) ? $ag['resume'] : null;
			if ($postId < 1 || $payload === []) {
				$state['active_gallery'] = null;
			} else {
				try {
					$r = RemoteGalleryImporter::importFromRemotePayloadResumable($postId, $payload, $resume, $galleryBatch);
				} catch (\Throwable $e) {
					$state['errors'][]       = (string) $postId . ': ' . $e->getMessage();
					$state['active_gallery'] = null;
					$progress->addLine((string) $postId . ': ' . $e->getMessage());
					$r = null;
				}

				if ($r !== null) {
					if (isset($r['error']) && \is_string($r['error']) && $r['error'] !== '') {
						$state['errors'][]       = $r['error'];
						$state['active_gallery'] = null;
						$progress->addLine($r['error']);
					} elseif (! empty($r['done'])) {
						/** @var list<int> $ids */
						$ids = isset($r['attachment_ids']) && \is_array($r['attachment_ids']) ? $r['attachment_ids'] : [];
						self::persistUnitCoreGalleryIds($postId, $ids);
						$state['active_gallery'] = null;
						$progress->addLine(
							\sprintf(
								/* translators: %d: WordPress post ID */
								\__('Gallery import finished for unit #%d.', 'booking-engine-connector'),
								$postId
							)
						);
					} else {
						$state['active_gallery']['resume'] = isset($r['resume']) && \is_array($r['resume'])
							? $r['resume']
							: null;
					}
				}
			}
		}

		if (($state['active_gallery'] ?? null) !== null) {
			SyncManualBatchState::set($uid, $runRaw, $state);
			/** @var list<array<string, mixed>> $rows */
			$rows = isset($state['rows']) && \is_array($state['rows']) ? $state['rows'] : [];
			$progress->setCounters(\min((int) ( $state['row_cursor'] ?? 0 ), \count($rows)), \count($rows));
			\wp_send_json_success([ 'done' => false ]);
		}

		/** @var list<array<string, mixed>> $rows */
		$rows      = isset($state['rows']) && \is_array($state['rows']) ? $state['rows'] : [];
		$rowCursor = (int) ( $state['row_cursor'] ?? 0 );
		$total     = \count($rows);

		if ($rowCursor < $total) {
			$row = $rows[ $rowCursor ];
			++$state['row_cursor'];
			$row = (array) \apply_filters('bec_sync_remote_unit', $row, $slug);

			$externalId = (string) ($row['external_id'] ?? '');
			if ($externalId === '') {
				++$state['skipped'];
				$progress->setCounters($rowCursor + 1, $total);
				$progress->addLine(
					\sprintf(
						/* translators: 1: current index, 2: total rows */
						\__('Row %1$d/%2$d: skipped (no external ID).', 'booking-engine-connector'),
						$rowCursor + 1,
						$total
					)
				);
			} else {
				$label = $service->resolveRowTitleForProgress($slug, $row);
				$progress->setCounters($rowCursor + 1, $total);
				$progress->addLine(
					\sprintf(
						/* translators: 1: current index, 2: total rows, 3: unit title */
						\__('Processing %1$d/%2$d: %3$s', 'booking-engine-connector'),
						$rowCursor + 1,
						$total,
						$label
					)
				);

				try {
					$pack   = $service->upsertRemoteRowForManualBatch($slug, $row, true);
					$result = $pack['result'];
					if ($result === 'created') {
						++$state['created'];
						$progress->addLine(
							\sprintf(
								/* translators: %s: external id */
								\__('Created unit for external ID %s.', 'booking-engine-connector'),
								$externalId
							)
						);
					} elseif ($result === 'updated') {
						++$state['updated'];
						$progress->addLine(
							\sprintf(
								/* translators: %s: external id */
								\__('Updated unit for external ID %s.', 'booking-engine-connector'),
								$externalId
							)
						);
					} else {
						++$state['skipped'];
						$progress->addLine(
							\sprintf(
								/* translators: %s: external id */
								\__('Skipped external ID %s.', 'booking-engine-connector'),
								$externalId
							)
						);
					}

					$dg = isset($pack['deferred_gallery']) && \is_array($pack['deferred_gallery']) ? $pack['deferred_gallery'] : null;
					$pid = (int) ( $pack['post_id'] ?? 0 );
					if ($dg !== null && $pid > 0) {
						$state['gallery_queue'][] = [
							'post_id' => $pid,
							'payload' => $dg,
						];
					}
				} catch (\Throwable $e) {
					$state['errors'][] = $externalId . ': ' . $e->getMessage();
					$progress->addLine($externalId . ': ' . $e->getMessage());
				}
			}
		}

		$rowCursor = (int) ( $state['row_cursor'] ?? 0 );
		/** @var list<array<string, mixed>> $gq */
		$gq       = isset($state['gallery_queue']) && \is_array($state['gallery_queue']) ? $state['gallery_queue'] : [];
		$complete = $rowCursor >= $total && $gq === [] && ($state['active_gallery'] ?? null) === null;

		if (! $complete) {
			SyncManualBatchState::set($uid, $runRaw, $state);
			\wp_send_json_success([ 'done' => false ]);
		}

		$result = [
			'created' => (int) ( $state['created'] ?? 0 ),
			'updated' => (int) ( $state['updated'] ?? 0 ),
			'skipped' => (int) ( $state['skipped'] ?? 0 ),
			'errors'  => isset($state['errors']) && \is_array($state['errors']) ? self::normalizeStringList($state['errors']) : [],
		];

		SyncManualBatchState::delete($uid, $runRaw);
		SyncLock::releaseManual($uid, $san);
		\set_transient(self::resultTransientKey(), $result, 120);
		\update_option('bec_sync_last_run_at', \current_time('mysql'), false);

		$progress->done($result);

		\wp_send_json_success([ 'done' => true, 'result' => $result ]);
	}

	/**
	 * @param list<mixed> $raw
	 * @return list<string>
	 */
	private static function normalizeStringList(array $raw): array
	{
		$out = [];
		foreach ($raw as $v) {
			$s = \mb_substr(\wp_strip_all_tags((string) $v), 0, 500);
			if ($s !== '') {
				$out[] = $s;
			}
		}

		return $out;
	}

	private static function persistUnitCoreGalleryIds(int $postId, array $ids): void
	{
		if ($postId < 1) {
			return;
		}

		$metaKey = CoreUnitMetaKeys::definitions()[ CoreUnitSemantic::GALLERY ]['meta_key'];
		\update_post_meta($postId, $metaKey, $ids);
		\do_action('bec_after_unit_gallery_sync', $postId, $ids);
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

		\wp_safe_redirect(\add_query_arg(
			[
				'page'      => self::PAGE_SLUG,
				'bec_saved' => '1',
				'tab'       => self::TAB_SETTINGS,
			],
			\admin_url('admin.php')
		));
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

		self::redirectToSyncPage([
			'bec_sync_done' => '1',
			'tab'           => self::TAB_TOOLS,
		]);
	}

	public static function handleClearRunningLock(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			\wp_die(\esc_html__('Insufficient permissions.', 'booking-engine-connector'));
		}

		\check_admin_referer('bec_sync_clear_running_lock', 'bec_sync_clear_running_lock_nonce');

		if (! (bool) \apply_filters('bec_sync_allow_admin_clear_lock', true)) {
			\wp_die(\esc_html__('This action is disabled.', 'booking-engine-connector'));
		}

		SyncLock::forceReleaseAll();

		self::redirectToSyncPage([
			'bec_sync_lock_cleared' => '1',
			'tab'                   => self::TAB_TOOLS,
		]);
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

		self::redirectToSyncPage(['tab' => self::TAB_TOOLS]);
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

		self::redirectToSyncPage(['tab' => self::TAB_TOOLS]);
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
		self::redirectToSyncPage(['tab' => self::TAB_SETTINGS]);
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

		if (isset($_GET['page']) && (string) \sanitize_key(\wp_unslash((string) $_GET['page'])) === self::PAGE_SLUG && isset($_GET['bec_sync_lock_cleared'])) {
			if ((string) \sanitize_text_field(\wp_unslash((string) $_GET['bec_sync_lock_cleared'])) === '1') {
				echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html__('Sync lock was cleared.', 'booking-engine-connector') . '</p></div>';
			}
		}
	}

	/**
	 * @return array{created:int, updated:int, skipped:int, errors: list<string>}
	 */
	private static function executeFullSyncAndStoreResult(): array
	{
		self::prepareLongRunningSync();

		$progress  = self::progressFromRequest();
		$runRaw    = isset($_POST['bec_sync_run_id']) ? (string) \wp_unslash((string) $_POST['bec_sync_run_id']) : '';
		$manualId  = SyncProgressReporter::isValidRunId($runRaw) ? $runRaw : null;
		$service   = new SyncService();
		$result    = $service->syncAll($progress, $manualId);
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
