<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Applies unit filters to the native {@see bec_unit} post type archive main query.
 */
final class UnitFilterQueryHooks
{
	public static function register(): void
	{
		\add_action('pre_get_posts', [self::class, 'onPreGetPosts'], 20);
	}

	public static function onPreGetPosts(\WP_Query $query): void
	{
		if (\is_admin() || ! $query->is_main_query()) {
			return;
		}

		if (! $query->is_post_type_archive(UnitPostType::getSlug())) {
			return;
		}

		$request = UnitFilterRequest::fromRequest();
		if (! $request->hasActiveFilters()) {
			return;
		}

		UnitFilterQueryApplier::apply($query, $request);
	}
}
