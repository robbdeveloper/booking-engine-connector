<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin;

use BookingEngineConnector\Admin\Settings\ConnectionPage;
use BookingEngineConnector\Admin\Settings\FallbackPage;
use BookingEngineConnector\Admin\Settings\StylingPage;
use BookingEngineConnector\Admin\Settings\UnitPermalinkPage;
use BookingEngineConnector\Admin\SyncAdmin;

/**
 * Registers top-level admin menu and subpages (expanded in later tasks).
 */
final class AdminMenu
{
	public const CAPABILITY = 'manage_options';

	public static function register(): void
	{
		add_action('admin_menu', [self::class, 'addMenus']);
	}

	public static function addMenus(): void
	{
		if (! current_user_can(self::CAPABILITY)) {
			return;
		}

		add_menu_page(
			__('Booking Engine', 'booking-engine-connector'),
			__('Booking Engine', 'booking-engine-connector'),
			self::CAPABILITY,
			'bec-dashboard',
			[self::class, 'renderDashboard'],
			'dashicons-calendar-alt',
			58
		);

		add_submenu_page(
			'bec-dashboard',
			__('Dashboard', 'booking-engine-connector'),
			__('Dashboard', 'booking-engine-connector'),
			self::CAPABILITY,
			'bec-dashboard',
			[self::class, 'renderDashboard']
		);

		add_submenu_page(
			'bec-dashboard',
			__('Connection', 'booking-engine-connector'),
			__('Connection', 'booking-engine-connector'),
			self::CAPABILITY,
			'bec-connection',
			[ConnectionPage::class, 'render']
		);

		add_submenu_page(
			'bec-dashboard',
			__('Styling', 'booking-engine-connector'),
			__('Styling', 'booking-engine-connector'),
			self::CAPABILITY,
			StylingPage::PAGE_SLUG,
			[StylingPage::class, 'render']
		);

		add_submenu_page(
			'bec-dashboard',
			__('Sync', 'booking-engine-connector'),
			__('Sync', 'booking-engine-connector'),
			self::CAPABILITY,
			SyncAdmin::PAGE_SLUG,
			[SyncAdmin::class, 'renderPage']
		);

		add_submenu_page(
			'bec-dashboard',
			__('Units — permalinks', 'booking-engine-connector'),
			__('Units — permalinks', 'booking-engine-connector'),
			self::CAPABILITY,
			UnitPermalinkPage::PAGE_SLUG,
			[UnitPermalinkPage::class, 'render']
		);

		add_submenu_page(
			'bec-dashboard',
			__('API Log', 'booking-engine-connector'),
			__('API Log', 'booking-engine-connector'),
			self::CAPABILITY,
			'bec-api-log',
			[ApiLogPage::class, 'render']
		);

		add_submenu_page(
			'bec-dashboard',
			__('Checkout & fallback', 'booking-engine-connector'),
			__('Checkout & fallback', 'booking-engine-connector'),
			self::CAPABILITY,
			FallbackPage::PAGE_SLUG,
			[FallbackPage::class, 'render']
		);
	}

	public static function renderDashboard(): void
	{
		echo '<div class="wrap"><h1>' . esc_html__('Booking Engine Connector', 'booking-engine-connector') . '</h1>';
		echo '<p>' . esc_html__('Configure connection, sync, and shortcodes from the submenus.', 'booking-engine-connector') . '</p></div>';
	}

}
