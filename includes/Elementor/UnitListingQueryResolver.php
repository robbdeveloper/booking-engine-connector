<?php

declare(strict_types=1);

namespace BookingEngineConnector\Elementor;

use BookingEngineConnector\PostTypes\UnitPostType;
use ElementorPro\Modules\QueryControl\Controls\Group_Control_Posts;
use WP_Query;

/**
 * Resolves the base {@see WP_Query} constraints for a BEC Elementor Loop Grid so
 * `[bec_available_units_count]` mirrors the same filtered unit set as Query ID
 * `bec_available_only` / `bec_filtered_units`.
 */
final class UnitListingQueryResolver
{
	/** @var array<string, WP_Query> */
	private static array $capturedBaseQueries = [];

	/** @var list<string> */
	private const LOOP_WIDGET_TYPES = [
		'loop-grid',
		'loop-carousel',
		'posts',
		'portfolio',
	];

	/** @var array<string, list<string>> */
	private const WIDGET_QUERY_PREFIXES = [
		'loop-grid'      => [ 'post_query', 'query' ],
		'loop-carousel'  => [ 'post_query', 'query' ],
		'posts'          => [ 'posts' ],
		'portfolio'      => [ 'portfolio' ],
	];

	public static function register(): void
	{
		if (! \class_exists('\Elementor\Plugin')) {
			return;
		}

		foreach (AvailabilityQueryFilter::getUnitFilterQueryIds() as $queryId) {
			if ($queryId === '') {
				continue;
			}
			\add_action(
				'elementor/query/' . $queryId,
				static function (WP_Query $query, $widget = null) use ($queryId): void {
					self::captureBaseQueryForId($queryId, $query, $widget);
				},
				5,
				2
			);
		}
	}

	/**
	 * @param mixed $widget Elementor widget instance when available.
	 */
	public static function captureBaseQueryForId(string $queryId, WP_Query $query, $widget = null): void
	{
		$queryId = \sanitize_key($queryId);
		if ($queryId === '') {
			return;
		}

		unset($widget);

		self::$capturedBaseQueries[ $queryId ] = self::cloneQueryVars($query);
	}

	/**
	 * Resolve the listing query that mirrors a BEC Elementor loop grid on this request.
	 */
	public static function resolve(string $preferredQueryId = ''): ?WP_Query
	{
		if (! self::isElementorAvailable()) {
			return null;
		}

		$queryIds = self::resolveQueryIds($preferredQueryId);

		foreach ($queryIds as $queryId) {
			if (isset(self::$capturedBaseQueries[ $queryId ])) {
				return self::cloneQueryVars(self::$capturedBaseQueries[ $queryId ]);
			}
		}

		foreach (self::documentIdsForCurrentRequest() as $documentId) {
			foreach ($queryIds as $queryId) {
				$resolved = self::resolveFromDocument($documentId, $queryId);
				if ($resolved instanceof WP_Query) {
					return $resolved;
				}
			}
		}

		return null;
	}

	private static function isElementorAvailable(): bool
	{
		return \class_exists('\Elementor\Plugin') && \class_exists('\ElementorPro\Plugin');
	}

	/**
	 * @return list<string>
	 */
	private static function resolveQueryIds(string $preferredQueryId): array
	{
		$preferredQueryId = \sanitize_key(\trim($preferredQueryId));
		$ids              = AvailabilityQueryFilter::getUnitFilterQueryIds();

		if ($preferredQueryId !== '') {
			\array_unshift($ids, $preferredQueryId);
		}

		$out = [];
		foreach ($ids as $id) {
			$key = \sanitize_key((string) $id);
			if ($key !== '' && ! \in_array($key, $out, true)) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * @return list<int>
	 */
	private static function documentIdsForCurrentRequest(): array
	{
		$ids = [];

		$queriedId = (int) \get_queried_object_id();
		if ($queriedId > 0) {
			$ids[] = $queriedId;
		}

		try {
			$plugin = \Elementor\Plugin::instance();
			if (isset($plugin->documents)) {
				$document = $plugin->documents->get_current();
				if ($document && \method_exists($document, 'get_main_id')) {
					$mainId = (int) $document->get_main_id();
					if ($mainId > 0) {
						$ids[] = $mainId;
					}
				}
			}
		} catch (\Throwable $e) {
			unset($e);
		}

		$out = [];
		foreach ($ids as $id) {
			if ($id > 0 && ! \in_array($id, $out, true)) {
				$out[] = $id;
			}
		}

		/**
		 * Post IDs whose Elementor data should be scanned when resolving the unit listing query for counts.
		 *
		 * @param list<int> $out
		 */
		return (array) \apply_filters('bec_elementor_unit_listing_document_ids', $out);
	}

	private static function resolveFromDocument(int $documentId, string $queryId): ?WP_Query
	{
		$meta = \get_post_meta($documentId, '_elementor_data', true);
		$tree = self::parseElementorData($meta);
		if ($tree === null) {
			return null;
		}

		$match = self::findLoopWidgetMatch($tree, $queryId);
		if ($match === null) {
			return null;
		}

		return self::buildQueryFromWidgetMatch($match);
	}

	/**
	 * @param mixed $meta
	 * @return list<array<string, mixed>>|null
	 */
	private static function parseElementorData($meta): ?array
	{
		if (\is_array($meta)) {
			return $meta;
		}
		if (! \is_string($meta) || $meta === '') {
			return null;
		}

		$decoded = \json_decode($meta, true);
		if (\JSON_ERROR_NONE === \json_last_error() && \is_array($decoded)) {
			return $decoded;
		}

		return null;
	}

	/**
	 * @param list<array<string, mixed>> $elements
	 * @return array{widgetType: string, settings: array<string, mixed>, queryPrefix: string}|null
	 */
	private static function findLoopWidgetMatch(array $elements, string $queryId): ?array
	{
		foreach ($elements as $element) {
			if (! \is_array($element)) {
				continue;
			}

			if (($element['elType'] ?? '') === 'widget') {
				$widgetType = (string) ($element['widgetType'] ?? '');
				if (! \in_array($widgetType, self::LOOP_WIDGET_TYPES, true)) {
					continue;
				}

				$settings = $element['settings'] ?? [];
				if (! \is_array($settings)) {
					continue;
				}

				$prefix = self::matchingQueryPrefix($widgetType, $settings, $queryId);
				if ($prefix !== '') {
					return [
						'widgetType'  => $widgetType,
						'settings'    => $settings,
						'queryPrefix' => $prefix,
					];
				}
			}

			$children = $element['elements'] ?? null;
			if (\is_array($children) && $children !== []) {
				$nested = self::findLoopWidgetMatch($children, $queryId);
				if ($nested !== null) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function matchingQueryPrefix(string $widgetType, array $settings, string $queryId): string
	{
		foreach (self::WIDGET_QUERY_PREFIXES[ $widgetType ] ?? [] as $prefix) {
			$key = $prefix . '_query_id';
			if (! isset($settings[ $key ])) {
				continue;
			}
			if (\sanitize_key((string) $settings[ $key ]) === $queryId) {
				return $prefix;
			}
		}

		return '';
	}

	/**
	 * @param array{widgetType: string, settings: array<string, mixed>, queryPrefix: string} $match
	 */
	private static function buildQueryFromWidgetMatch(array $match): ?WP_Query
	{
		$settings = $match['settings'];
		$prefix   = $match['queryPrefix'];

		$postType = isset($settings[ $prefix . '_post_type' ]) ? (string) $settings[ $prefix . '_post_type' ] : '';
		if ($postType === 'current_query') {
			global $wp_query;
			if ($wp_query instanceof WP_Query && self::isNativeUnitListingQuery($wp_query)) {
				return self::cloneQueryVars($wp_query);
			}

			return null;
		}

		$queryArgs = self::buildElementorQueryArgs($prefix, $settings);
		if ($queryArgs === null) {
			return null;
		}

		return self::queryFromArgs($queryArgs);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>|null
	 */
	private static function buildElementorQueryArgs(string $prefix, array $settings): ?array
	{
		try {
			$controls = \ElementorPro\Plugin::elementor()->controls_manager->get_control_groups(
				Group_Control_Posts::get_type()
			);
		} catch (\Throwable $e) {
			unset($e);

			return null;
		}

		if (! $controls instanceof Group_Control_Posts) {
			return null;
		}

		/** @var array<string, mixed> $queryArgs */
		$queryArgs = $controls->get_query_args($prefix, $settings);
		if ($queryArgs === []) {
			return null;
		}

		$queryArgs['posts_per_page'] = -1;
		$queryArgs['fields']         = 'ids';
		$queryArgs['no_found_rows']  = true;

		return $queryArgs;
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function queryFromArgs(array $args): WP_Query
	{
		$query = new WP_Query();
		foreach ($args as $key => $value) {
			$query->set((string) $key, $value);
		}

		return $query;
	}

	private static function cloneQueryVars(WP_Query $source): WP_Query
	{
		$query = new WP_Query();
		foreach (self::listingQueryVarKeys() as $key) {
			$value = $source->get($key);
			if ($value === '' || $value === null || $value === false) {
				continue;
			}
			if (\is_array($value) && $value === []) {
				continue;
			}
			$query->set($key, $value);
		}

		return $query;
	}

	/**
	 * @return list<string>
	 */
	private static function listingQueryVarKeys(): array
	{
		return [
			'post_type',
			'post_status',
			'post__in',
			'post__not_in',
			'post_parent',
			'tax_query',
			'taxonomy',
			'term',
			'bec_unit_category',
			'meta_query',
			'author',
			'author__in',
			'author__not_in',
			'date_query',
			'orderby',
			'order',
			'posts_per_page',
			'ignore_sticky_posts',
			'lang',
		];
	}

	private static function isNativeUnitListingQuery(WP_Query $query): bool
	{
		$slug = UnitPostType::getSlug();

		if ($query->is_post_type_archive($slug)) {
			return true;
		}

		return $query->is_tax(\BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy::TAXONOMY);
	}
}
