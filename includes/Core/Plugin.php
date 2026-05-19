<?php

declare(strict_types=1);

namespace BookingEngineConnector\Core;

use BookingEngineConnector\Admin\AdminMenu;
use BookingEngineConnector\Admin\Settings\ConnectionPage;
use BookingEngineConnector\Admin\Settings\FallbackPage;
use BookingEngineConnector\Admin\Settings\StylingPage;
use BookingEngineConnector\Admin\Settings\UnitPermalinkPage;
use BookingEngineConnector\Admin\SyncAdmin;
use BookingEngineConnector\Elementor\AvailabilityQueryFilter;
use BookingEngineConnector\Elementor\DynamicTagsRegistrar;
use BookingEngineConnector\Front\AmenitiesAssets;
use BookingEngineConnector\Front\PublicAssets;
use BookingEngineConnector\Integrations\Multilingual;
use BookingEngineConnector\Front\PublicContentBlocks;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\SearchSettings;
use BookingEngineConnector\Search\SearchTemplateHooks;
use BookingEngineConnector\Shortcodes\ShortcodeRegistry;
use BookingEngineConnector\Styling\StylingSettings;
use BookingEngineConnector\Sync\CoreUnitFieldRegistry;
use BookingEngineConnector\Sync\SyncCron;
use BookingEngineConnector\Sync\UnitCategorySync;
use BookingEngineConnector\Sync\UnitSyncFieldRegistry;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Main plugin bootstrap.
 */
final class Plugin
{
	private static ?self $instance = null;

	public static function instance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void
	{
		register_activation_hook(\BEC_PLUGIN_FILE, [Activator::class, 'activate']);
		register_deactivation_hook(\BEC_PLUGIN_FILE, [Deactivator::class, 'deactivate']);

		add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
	}

	public function onPluginsLoaded(): void
	{
		require_once \BEC_PLUGIN_DIR . 'includes/Search/template-functions.php';

		load_plugin_textdomain(
			'booking-engine-connector',
			false,
			dirname(\BEC_PLUGIN_BASENAME) . '/languages'
		);

		Multilingual::register();

		PluginScreenLinks::register();
		PluginUpdater::register();

		SearchSettings::register();
		SearchTemplateHooks::register();
		PublicAssets::register();
		AmenitiesAssets::register();
		PublicContentBlocks::register();
		AvailabilityQueryFilter::register();
		DynamicTagsRegistrar::register();
		AdminMenu::register();
		StylingSettings::register();
		ConnectionPage::register();
		StylingPage::register();
		UnitPermalinkPage::register();
		FallbackPage::register();
		SyncCron::register();
		SyncAdmin::register();
		UnitPostType::register();
		UnitCategoryTaxonomy::register();
		CoreUnitFieldRegistry::register();
		UnitSyncFieldRegistry::register();
		UnitCategorySync::register();
		ShortcodeRegistry::register();
	}
}
