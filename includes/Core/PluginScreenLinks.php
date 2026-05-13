<?php

declare(strict_types=1);

namespace BookingEngineConnector\Core;

/**
 * Adds documentation and related links on the Plugins admin screen.
 */
final class PluginScreenLinks
{
	/** Hosted user-facing documentation. */
	private const DOCUMENTATION_URI = 'https://bec-docs.apps.robb.cx';

	public static function register(): void
	{
		\add_filter(
			'plugin_action_links_' . \BEC_PLUGIN_BASENAME,
			[self::class, 'actionLinks']
		);
	}

	/**
	 * @param array<int, string> $links Existing action links from WordPress/core.
	 * @return array<int, string>
	 */
	public static function actionLinks(array $links): array
	{
		$href = '<a href="' . \esc_url(self::DOCUMENTATION_URI) . '" target="_blank" rel="noopener noreferrer">'
			. \esc_html__('Documentation', 'booking-engine-connector')
			. '</a>';

		$links[] = $href;

		return $links;
	}
}
