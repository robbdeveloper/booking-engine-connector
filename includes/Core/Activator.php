<?php

declare(strict_types=1);

namespace BookingEngineConnector\Core;

use BookingEngineConnector\Fallback\FallbackSettings;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Providers\Contracts\ProviderErrorCategory;
use BookingEngineConnector\Sync\SyncCron;

/**
 * Runs on plugin activation.
 */
final class Activator
{
	public static function activate(): void
	{
		if (version_compare(PHP_VERSION, '8.0', '<')) {
			\deactivate_plugins(\plugin_basename(\BEC_PLUGIN_FILE));
			\wp_die(
				\esc_html__('Booking Engine Connector requires PHP 8.0 or newer.', 'booking-engine-connector'),
				\esc_html__('Plugin activation error', 'booking-engine-connector'),
				['response' => 500, 'back_link' => true]
			);
		}

		Migrations\MigrationRunner::runPending();

		\add_option(SyncCron::OPTION_INTERVAL_HOURS, 6, '', false);

		\add_option(FallbackSettings::OPTION_CHECKOUT_BASE_URL, '', '', false);
		\add_option(FallbackSettings::OPTION_CHECKOUT_HTTP_METHOD, 'get', '', false);
		\add_option(FallbackSettings::OPTION_ENABLED, 1, '', false);
		\add_option(FallbackSettings::OPTION_MODE, 'inline', '', false);
		\add_option(FallbackSettings::OPTION_FORCE, 0, '', false);
		\add_option(
			FallbackSettings::OPTION_TRIGGER_CATEGORIES,
			\wp_json_encode(
				[
					ProviderErrorCategory::RATE_LIMIT,
					ProviderErrorCategory::SERVER_ERROR,
					ProviderErrorCategory::AUTH,
				]
			),
			'',
			false
		);
		\add_option(FallbackSettings::OPTION_EMPTY_QUOTE, 0, '', false);
		\add_option(FallbackSettings::OPTION_LINK_URL, '', '', false);
		\add_option(FallbackSettings::OPTION_LINK_TEXT, '', '', false);
		\add_option(FallbackSettings::OPTION_INLINE_CONTENT, '', '', false);

		\add_option(UnitPostType::OPTION_PERMALINK_SLUG, '', '', false);
		UnitPostType::scheduleRewriteFlush();

		\add_filter('cron_schedules', [SyncCron::class, 'registerSchedule']);
		SyncCron::schedule();
	}
}
