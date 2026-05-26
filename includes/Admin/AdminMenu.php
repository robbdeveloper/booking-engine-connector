<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin;

use BookingEngineConnector\Admin\Settings\ConnectionPage;
use BookingEngineConnector\Admin\Settings\FallbackPage;
use BookingEngineConnector\Admin\Settings\FrontendPage;
use BookingEngineConnector\Admin\Settings\StylingPage;
use BookingEngineConnector\Admin\Settings\UnitFiltersPage;
use BookingEngineConnector\Admin\Settings\UnitPermalinkPage;
use BookingEngineConnector\Fallback\FallbackSettings;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Sync\SyncCron;
use BookingEngineConnector\Sync\SyncLock;

/**
 * Registers top-level admin menu and subpages.
 */
final class AdminMenu
{
	public const CAPABILITY = 'manage_options';

	public static function register(): void
	{
		AdminPageLayout::register();
		\add_action('admin_menu', [self::class, 'addMenus']);
	}

	public static function addMenus(): void
	{
		if (! \current_user_can(self::CAPABILITY)) {
			return;
		}

		\add_menu_page(
			\__('Booking Engine', 'booking-engine-connector'),
			\__('Booking Engine', 'booking-engine-connector'),
			self::CAPABILITY,
			'bec-dashboard',
			[self::class, 'renderDashboard'],
			'dashicons-calendar-alt',
			58
		);

		\add_submenu_page(
			'bec-dashboard',
			\__('Dashboard', 'booking-engine-connector'),
			\__('Dashboard', 'booking-engine-connector'),
			self::CAPABILITY,
			'bec-dashboard',
			[self::class, 'renderDashboard']
		);

		\add_submenu_page(
			'bec-dashboard',
			\__('Connection', 'booking-engine-connector'),
			\__('Connection', 'booking-engine-connector'),
			self::CAPABILITY,
			ConnectionPage::PAGE_SLUG,
			[ConnectionPage::class, 'render']
		);

		\add_submenu_page(
			'bec-dashboard',
			\__('Frontend', 'booking-engine-connector'),
			\__('Frontend', 'booking-engine-connector'),
			self::CAPABILITY,
			FrontendPage::PAGE_SLUG,
			[FrontendPage::class, 'render']
		);

		\add_submenu_page(
			'bec-dashboard',
			\__('Sync & Import', 'booking-engine-connector'),
			\__('Sync & Import', 'booking-engine-connector'),
			self::CAPABILITY,
			SyncAdmin::PAGE_SLUG,
			[SyncAdmin::class, 'renderPage']
		);

		\add_submenu_page(
			'bec-dashboard',
			\__('Units', 'booking-engine-connector'),
			\__('Units', 'booking-engine-connector'),
			self::CAPABILITY,
			UnitPermalinkPage::PAGE_SLUG,
			[UnitPermalinkPage::class, 'render']
		);

		\add_submenu_page(
			'bec-dashboard',
			\__('Listing Filters', 'booking-engine-connector'),
			\__('Listing Filters', 'booking-engine-connector'),
			self::CAPABILITY,
			UnitFiltersPage::PAGE_SLUG,
			[UnitFiltersPage::class, 'render']
		);

		\add_submenu_page(
			'bec-dashboard',
			\__('Design', 'booking-engine-connector'),
			\__('Design', 'booking-engine-connector'),
			self::CAPABILITY,
			StylingPage::PAGE_SLUG,
			[StylingPage::class, 'render']
		);

		\add_submenu_page(
			'bec-dashboard',
			\__('Checkout & Fallback', 'booking-engine-connector'),
			\__('Checkout & Fallback', 'booking-engine-connector'),
			self::CAPABILITY,
			FallbackPage::PAGE_SLUG,
			[FallbackPage::class, 'render']
		);

		\add_submenu_page(
			'bec-dashboard',
			\__('Tools & Logs', 'booking-engine-connector'),
			\__('Tools & Logs', 'booking-engine-connector'),
			self::CAPABILITY,
			'bec-api-log',
			[ApiLogPage::class, 'render']
		);
	}

	public static function renderDashboard(): void
	{
		if (! \current_user_can(self::CAPABILITY)) {
			return;
		}

		$providerSlug = ProviderRegistry::getActiveSlug();
		$provider     = ProviderRegistry::getProvider($providerSlug);
		$credsOk      = $provider->validateCredentials();

		$lastSync = (string) \get_option('bec_sync_last_run_at', '');
		$nextCron = \wp_next_scheduled(SyncCron::CRON_HOOK);
		$hours    = \max(1, (int) \get_option(SyncCron::OPTION_INTERVAL_HOURS, 6));
		$locked   = SyncLock::isLocked();

		$counts = \wp_count_posts(UnitPostType::getSlug());
		$unitCount = $counts instanceof \stdClass ? (int) ( $counts->publish ?? 0 ) : 0;

		$checkoutUrl = (string) \get_option(FallbackSettings::OPTION_CHECKOUT_BASE_URL, '');
		$fallbackOn  = (bool) \get_option(FallbackSettings::OPTION_ENABLED, true);
		$fallbackMode = (string) \get_option(FallbackSettings::OPTION_MODE, 'inline');

		AdminPageLayout::wrapOpen(
			\__('Dashboard', 'booking-engine-connector'),
			\__(
				'Overview of connection health, sync status, and quick links to configure the plugin.',
				'booking-engine-connector'
			)
		);

		AdminPageLayout::statusGridOpen();

		AdminPageLayout::statusCard(
			\__('Active provider', 'booking-engine-connector'),
			\ucfirst($providerSlug),
			'',
			'',
			\admin_url('admin.php?page=' . ConnectionPage::PAGE_SLUG),
			\__('Manage connection', 'booking-engine-connector')
		);

		AdminPageLayout::statusCard(
			\__('Credentials', 'booking-engine-connector'),
			$credsOk
				? \__('Complete', 'booking-engine-connector')
				: \__('Incomplete', 'booking-engine-connector'),
			AdminPageLayout::badge(
				$credsOk
					? \__('Ready', 'booking-engine-connector')
					: \__('Action needed', 'booking-engine-connector'),
				$credsOk ? 'success' : 'warning'
			),
			'',
			\admin_url('admin.php?page=' . ConnectionPage::PAGE_SLUG),
			\__('Verify connection', 'booking-engine-connector')
		);

		AdminPageLayout::statusCard(
			\__('Last sync', 'booking-engine-connector'),
			$lastSync !== ''
				? $lastSync
				: \__('Never completed', 'booking-engine-connector'),
			'',
			$lastSync === ''
				? \__('Run a sync after configuring credentials.', 'booking-engine-connector')
				: '',
			\admin_url('admin.php?page=' . SyncAdmin::PAGE_SLUG),
			\__('Open sync settings', 'booking-engine-connector')
		);

		$nextLabel = $nextCron !== false
			? \wp_date(\get_option('date_format') . ' ' . \get_option('time_format'), (int) $nextCron)
			: \__('Not scheduled', 'booking-engine-connector');

		AdminPageLayout::statusCard(
			\__('Next scheduled sync', 'booking-engine-connector'),
			$nextLabel,
			'',
			\sprintf(
				/* translators: %d: interval in hours */
				\__('Every %d hours via WP-Cron.', 'booking-engine-connector'),
				$hours
			),
			\admin_url('admin.php?page=' . SyncAdmin::PAGE_SLUG),
			\__('Adjust schedule', 'booking-engine-connector')
		);

		AdminPageLayout::statusCard(
			\__('Sync lock', 'booking-engine-connector'),
			$locked
				? \__('Lock is set', 'booking-engine-connector')
				: \__('No lock', 'booking-engine-connector'),
			AdminPageLayout::badge(
				$locked
					? \__('In progress or stale', 'booking-engine-connector')
					: \__('Idle', 'booking-engine-connector'),
				$locked ? 'warning' : 'success'
			),
			'',
			\admin_url('admin.php?page=' . SyncAdmin::PAGE_SLUG),
			\__('Manage sync lock', 'booking-engine-connector')
		);

		AdminPageLayout::statusCard(
			\__('Synced units', 'booking-engine-connector'),
			(string) $unitCount,
			'',
			\__('Published unit posts in WordPress.', 'booking-engine-connector'),
			\admin_url('edit.php?post_type=' . UnitPostType::getSlug()),
			\__('View units', 'booking-engine-connector')
		);

		AdminPageLayout::statusCard(
			\__('Checkout URL', 'booking-engine-connector'),
			$checkoutUrl !== ''
				? \__('Configured', 'booking-engine-connector')
				: \__('Not set', 'booking-engine-connector'),
			AdminPageLayout::badge(
				$checkoutUrl !== ''
					? \__('Ready', 'booking-engine-connector')
					: \__('Missing', 'booking-engine-connector'),
				$checkoutUrl !== '' ? 'success' : 'neutral'
			),
			'',
			\admin_url('admin.php?page=' . FallbackPage::PAGE_SLUG),
			\__('Configure checkout', 'booking-engine-connector')
		);

		$fallbackLabel = $fallbackOn
			? ( $fallbackMode === 'link'
				? \__('Enabled — link mode', 'booking-engine-connector')
				: \__('Enabled — inline mode', 'booking-engine-connector') )
			: \__('Disabled', 'booking-engine-connector');

		AdminPageLayout::statusCard(
			\__('Fallback', 'booking-engine-connector'),
			$fallbackLabel,
			AdminPageLayout::badge(
				$fallbackOn
					? \__('Active', 'booking-engine-connector')
					: \__('Off', 'booking-engine-connector'),
				$fallbackOn ? 'success' : 'neutral'
			),
			'',
			\admin_url('admin.php?page=' . FallbackPage::PAGE_SLUG),
			\__('Configure fallback', 'booking-engine-connector')
		);

		AdminPageLayout::statusGridClose();

		AdminPageLayout::cardOpen(
			\__('Quick actions', 'booking-engine-connector'),
			\__('Jump to the most common setup and maintenance tasks.', 'booking-engine-connector')
		);

		AdminPageLayout::quickActionsOpen();
		AdminPageLayout::quickAction(
			\admin_url('admin.php?page=' . ConnectionPage::PAGE_SLUG),
			\__('Set up connection', 'booking-engine-connector'),
			\__('Provider credentials and connection test.', 'booking-engine-connector')
		);
		AdminPageLayout::quickAction(
			\admin_url('admin.php?page=' . SyncAdmin::PAGE_SLUG),
			\__('Run sync', 'booking-engine-connector'),
			\__('Import or update units from the booking engine.', 'booking-engine-connector')
		);
		AdminPageLayout::quickAction(
			\admin_url('admin.php?page=' . FrontendPage::PAGE_SLUG),
			\__('Frontend settings', 'booking-engine-connector'),
			\__('Search form guest fields and single-unit content.', 'booking-engine-connector')
		);
		AdminPageLayout::quickAction(
			\admin_url('admin.php?page=' . UnitPermalinkPage::PAGE_SLUG),
			\__('Unit URLs', 'booking-engine-connector'),
			\__('Permalinks, archives, and URL structures.', 'booking-engine-connector')
		);
		AdminPageLayout::quickAction(
			\admin_url('admin.php?page=' . UnitFiltersPage::PAGE_SLUG),
			\__('Listing filters', 'booking-engine-connector'),
			\__('Choose amenities for `[bec_unit_filters]`.', 'booking-engine-connector')
		);
		AdminPageLayout::quickAction(
			\admin_url('admin.php?page=' . StylingPage::PAGE_SLUG),
			\__('Design & styling', 'booking-engine-connector'),
			\__('Presets, tokens, and extra CSS.', 'booking-engine-connector')
		);
		AdminPageLayout::quickAction(
			\admin_url('admin.php?page=' . FallbackPage::PAGE_SLUG),
			\__('Checkout & fallback', 'booking-engine-connector'),
			\__('External booking URL and contact fallback.', 'booking-engine-connector')
		);
		AdminPageLayout::quickAction(
			\admin_url('admin.php?page=bec-api-log'),
			\__('API request log', 'booking-engine-connector'),
			\__('Review recent provider API calls.', 'booking-engine-connector')
		);
		AdminPageLayout::quickActionsClose();

		AdminPageLayout::cardClose();

		AdminPageLayout::wrapClose();
	}
}
