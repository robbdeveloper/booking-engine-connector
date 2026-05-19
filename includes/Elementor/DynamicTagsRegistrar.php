<?php

declare(strict_types=1);

namespace BookingEngineConnector\Elementor;

use BookingEngineConnector\Elementor\DynamicTags\UnitGalleryTag;
use Elementor\Core\DynamicTags\Manager;

/**
 * Registers BEC Elementor dynamic tags (gallery, etc.).
 */
final class DynamicTagsRegistrar
{
	public static function register(): void
	{
		\add_action('elementor/dynamic_tags/register', [self::class, 'onRegister']);
	}

	/**
	 * @param Manager $dynamicTagsManager
	 */
	public static function onRegister($dynamicTagsManager): void
	{
		if (! $dynamicTagsManager instanceof Manager) {
			return;
		}

		$dynamicTagsManager->register_group(
			'bec',
			[
				'title' => \esc_html__('Booking Engine', 'booking-engine-connector'),
			]
		);

		$dynamicTagsManager->register(new UnitGalleryTag());
	}
}
