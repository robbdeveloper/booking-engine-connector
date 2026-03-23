<?php

declare(strict_types=1);

namespace BookingEngineConnector\Front;

use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Enqueues minimal public styles (TASK-UI-001).
 */
final class PublicAssets
{
	public static function register(): void
	{
		\add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
	}

	public static function enqueue(): void
	{
		if (! self::shouldLoad()) {
			return;
		}

		\wp_enqueue_style(
			'bec-public',
			\BEC_PLUGIN_URL . 'assets/public.css',
			[],
			\BEC_VERSION
		);
	}

	private static function shouldLoad(): bool
	{
		if (\is_singular(UnitPostType::getSlug())) {
			return true;
		}
		if (\is_post_type_archive(UnitPostType::getSlug())) {
			return true;
		}

		return (bool) \apply_filters('bec_enqueue_public_assets', false);
	}
}
