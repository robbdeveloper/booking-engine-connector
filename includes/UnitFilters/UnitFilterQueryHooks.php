<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Applies unit filters to native unit archive and unit category taxonomy main queries.
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

		if (
			! $query->is_post_type_archive(UnitPostType::getSlug())
			&& ! $query->is_tax(UnitCategoryTaxonomy::TAXONOMY)
		) {
			return;
		}

		$request = UnitFilterRequest::fromRequest();
		if (! $request->hasActiveFilters()) {
			return;
		}

		UnitFilterQueryApplier::apply($query, $request);
	}
}
