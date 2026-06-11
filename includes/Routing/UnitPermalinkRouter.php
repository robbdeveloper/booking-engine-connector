<?php

declare(strict_types=1);

namespace BookingEngineConnector\Routing;

use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Custom rewrite rules and permalink filters for selectable unit/category URL structures.
 */
final class UnitPermalinkRouter
{
	private const QUERY_BEC_ROUTED = 'bec_unit_permalink_routed';

	private const ML_REWRITE_RULES_VERSION = '2';

	/** Category slug segment in unit permalinks (validation only; not the taxonomy query var). */
	private const QUERY_UNIT_CATEGORY_SLUG = 'bec_unit_category_slug';

	public static function register(): void
	{
		add_filter('bec_unit_category_taxonomy_args', [self::class, 'filterTaxonomyArgs'], 10, 2);
		add_action('init', [self::class, 'onInit'], 20);
		add_action('wpml_loaded', [self::class, 'onMultilingualLoaded'], 20);
		add_filter('rewrite_rules_array', [self::class, 'filterRewriteRulesArray'], 99);
		add_filter('query_vars', [self::class, 'filterQueryVars']);
		add_filter('post_type_link', [self::class, 'filterPostTypeLink'], 10, 2);
		add_filter('term_link', [self::class, 'filterTermLink'], 10, 3);
		add_action('parse_request', [self::class, 'parseRequest'], 11);
		add_action('pre_get_posts', [self::class, 'preGetPosts'], 5);
		add_action('wp', [self::class, 'onWp'], 0);
		self::registerMultilingualRewriteFlushHooks();
	}

	public static function onMultilingualLoaded(): void
	{
		if (\get_option('bec_ml_rewrite_rules_version', '') !== self::ML_REWRITE_RULES_VERSION) {
			\update_option('bec_ml_rewrite_rules_version', self::ML_REWRITE_RULES_VERSION, false);
			UnitPostType::scheduleRewriteFlush();
		}
	}

	/**
	 * Ensure language-prefixed variants exist when rewrite rules are compiled or flushed.
	 *
	 * @param array<string, string> $rules
	 * @return array<string, string>
	 */
	public static function filterRewriteRulesArray(array $rules): array
	{
		if (! MultilingualBridge::isActive() || ! MultilingualBridge::usesDirectoryLanguageUrls()) {
			return $rules;
		}

		$prefixedRules = [];

		foreach ($rules as $regex => $query) {
			if (! \is_string($regex) || ! \is_string($query) || ! \str_contains($query, self::QUERY_BEC_ROUTED)) {
				continue;
			}

			if (\str_contains($query, 'lang=')) {
				continue;
			}

			foreach (MultilingualBridge::getLanguagesWithPathPrefix() as $lang) {
				$prefix = MultilingualBridge::getPathPrefixForLanguage($lang);
				if ($prefix === '') {
					continue;
				}

				$prefixedRegex = self::prefixRewritePattern($regex, $prefix);
				if (isset($rules[ $prefixedRegex ]) || isset($prefixedRules[ $prefixedRegex ])) {
					continue;
				}

				$prefixedRules[ $prefixedRegex ] = $query . '&lang=' . rawurlencode($lang);
			}
		}

		if ($prefixedRules === []) {
			return $rules;
		}

		return $prefixedRules + $rules;
	}

	private static function registerMultilingualRewriteFlushHooks(): void
	{
		add_action('wpml_after_active_languages_update', [UnitPostType::class, 'scheduleRewriteFlush']);
		add_action('wpml_after_add_language', [UnitPostType::class, 'scheduleRewriteFlush']);
		add_action('wpml_after_delete_language', [UnitPostType::class, 'scheduleRewriteFlush']);
		add_action('pll_add_language', [UnitPostType::class, 'scheduleRewriteFlush']);
		add_action('pll_delete_language', [UnitPostType::class, 'scheduleRewriteFlush']);
	}

	/**
	 * Disable default taxonomy rewrite when a custom category structure is selected.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function filterTaxonomyArgs(array $args, string $taxonomy): array
	{
		if ($taxonomy !== UnitCategoryTaxonomy::TAXONOMY) {
			return $args;
		}

		if (! UnitCategoryTaxonomy::isEnabled()) {
			return $args;
		}

		if (UnitPermalinkSettings::usesCustomCategoryRewrite(UnitPermalinkSettings::resolveCategoryStructure())) {
			$args['rewrite'] = false;
		}

		return $args;
	}

	public static function onInit(): void
	{
		if (! UnitCategoryTaxonomy::isEnabled()) {
			return;
		}

		$unitSlug     = UnitPostType::getPermalinkSlug();
		$unitStruct   = UnitPermalinkSettings::resolveUnitStructure();
		$catStruct    = UnitPermalinkSettings::resolveCategoryStructure();

		if ($unitStruct === UnitPermalinkSettings::UNIT_BASE_CATEGORY) {
			self::addLocalizedRewriteRule(
				'^' . preg_quote($unitSlug, '/') . '/([^/]+)/([^/]+)/?$',
				'index.php?post_type=' . UnitPostType::POST_TYPE . '&name=$matches[2]&' . self::QUERY_UNIT_CATEGORY_SLUG . '=$matches[1]&' . self::QUERY_BEC_ROUTED . '=' . UnitPermalinkSettings::UNIT_BASE_CATEGORY,
				'top'
			);
		}

		if ($unitStruct === UnitPermalinkSettings::UNIT_CATEGORY_ONLY) {
			self::addLocalizedRewriteRule(
				'^([^/]+)/([^/]+)/?$',
				'index.php?post_type=' . UnitPostType::POST_TYPE . '&name=$matches[2]&' . self::QUERY_UNIT_CATEGORY_SLUG . '=$matches[1]&' . self::QUERY_BEC_ROUTED . '=' . UnitPermalinkSettings::UNIT_CATEGORY_ONLY,
				'bottom'
			);
		}

		if ($catStruct === UnitPermalinkSettings::CAT_UNIT_BASE) {
			$pathPattern = '^' . preg_quote($unitSlug, '/') . '/([^/]+)';
			self::addCategoryArchiveEndpointRules(
				$pathPattern,
				UnitPermalinkSettings::CAT_UNIT_BASE,
				'top'
			);
			self::addLocalizedRewriteRule(
				$pathPattern . '/?$',
				'index.php?bec_unit_category=$matches[1]&' . self::QUERY_BEC_ROUTED . '=' . UnitPermalinkSettings::CAT_UNIT_BASE,
				'top'
			);
		}

		if ($catStruct === UnitPermalinkSettings::CAT_BARE) {
			$pathPattern = '^([^/]+)';
			self::addCategoryArchiveEndpointRules(
				$pathPattern,
				UnitPermalinkSettings::CAT_BARE,
				'bottom'
			);
			self::addLocalizedRewriteRule(
				$pathPattern . '/?$',
				'index.php?bec_unit_category=$matches[1]&' . self::QUERY_BEC_ROUTED . '=' . UnitPermalinkSettings::CAT_BARE,
				'bottom'
			);
		}
	}

	private static function addLocalizedRewriteRule(string $pattern, string $query, string $priority): void
	{
		add_rewrite_rule($pattern, $query, $priority);

		if (! MultilingualBridge::isActive() || ! MultilingualBridge::usesDirectoryLanguageUrls()) {
			return;
		}

		foreach (MultilingualBridge::getLanguagesWithPathPrefix() as $lang) {
			$prefix = MultilingualBridge::getPathPrefixForLanguage($lang);
			if ($prefix === '') {
				continue;
			}

			add_rewrite_rule(
				self::prefixRewritePattern($pattern, $prefix),
				$query . '&lang=' . rawurlencode($lang),
				$priority
			);
		}
	}

	private static function prefixRewritePattern(string $pattern, string $langPrefix): string
	{
		$langPrefix = trim($langPrefix, '/');
		if ($langPrefix === '') {
			return $pattern;
		}

		if (str_starts_with($pattern, '^')) {
			return '^' . preg_quote($langPrefix, '/') . '/' . substr($pattern, 1);
		}

		return preg_quote($langPrefix, '/') . '/' . $pattern;
	}

	/**
	 * Register pagination, feed, and embed rewrite rules for custom category archive URL structures.
	 */
	private static function addCategoryArchiveEndpointRules(
		string $pathPattern,
		string $routedValue,
		string $priority
	): void {
		global $wp_rewrite;

		if (! $wp_rewrite instanceof \WP_Rewrite) {
			return;
		}

		$feedNames = implode('|', array_map(
			static fn (string $feed): string => preg_quote($feed, '/'),
			array_map('strval', (array) $wp_rewrite->feeds)
		));
		if ($feedNames === '') {
			$feedNames = 'feed|rdf|rss|rss2|atom';
		}

		$feedBase       = preg_quote((string) $wp_rewrite->feed_base, '/');
		$paginationBase = preg_quote((string) $wp_rewrite->pagination_base, '/');
		$baseQuery      = 'index.php?bec_unit_category=$matches[1]&' . self::QUERY_BEC_ROUTED . '=' . $routedValue;

		self::addLocalizedRewriteRule(
			$pathPattern . '/' . $feedBase . '/(' . $feedNames . ')/?$',
			$baseQuery . '&feed=$matches[2]',
			$priority
		);

		self::addLocalizedRewriteRule(
			$pathPattern . '/(' . $feedNames . ')/?$',
			$baseQuery . '&feed=$matches[2]',
			$priority
		);

		self::addLocalizedRewriteRule(
			$pathPattern . '/embed/?$',
			$baseQuery . '&embed=true',
			$priority
		);

		self::addLocalizedRewriteRule(
			$pathPattern . '/' . $paginationBase . '/?([0-9]{1,})/?$',
			$baseQuery . '&paged=$matches[2]',
			$priority
		);
	}

	/**
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public static function filterQueryVars(array $vars): array
	{
		$vars[] = self::QUERY_BEC_ROUTED;
		$vars[] = self::QUERY_UNIT_CATEGORY_SLUG;

		return $vars;
	}

	/**
	 * @param string  $postLink
	 * @param WP_Post $post
	 */
	public static function filterPostTypeLink($postLink, $post): string
	{
		if (! $post instanceof WP_Post || $post->post_type !== UnitPostType::POST_TYPE) {
			return (string) $postLink;
		}

		if (! UnitCategoryTaxonomy::isEnabled()) {
			return (string) $postLink;
		}

		$structure = UnitPermalinkSettings::resolveUnitStructure();
		if ($structure === UnitPermalinkSettings::UNIT_BASE) {
			return (string) $postLink;
		}

		$term = self::resolvePrimaryTermForPost((int) $post->ID);
		$unitSlug = UnitPostType::getPermalinkSlug();
		$postSlug = $post->post_name;

		if ($structure === UnitPermalinkSettings::UNIT_BASE_CATEGORY) {
			if ($term instanceof WP_Term) {
				$url = user_trailingslashit(home_url('/' . $unitSlug . '/' . $term->slug . '/' . $postSlug));
			} else {
				$url = user_trailingslashit(home_url('/' . $unitSlug . '/' . $postSlug));
			}

			return MultilingualBridge::localizeUrl($url, null, (int) $post->ID);
		}

		if ($structure === UnitPermalinkSettings::UNIT_CATEGORY_ONLY) {
			if ($term instanceof WP_Term) {
				$url = user_trailingslashit(home_url('/' . $term->slug . '/' . $postSlug));
			} else {
				$url = user_trailingslashit(home_url('/' . $unitSlug . '/' . $postSlug));
			}

			return MultilingualBridge::localizeUrl($url, null, (int) $post->ID);
		}

		return (string) $postLink;
	}

	/**
	 * @param string  $termlink
	 * @param WP_Term $term
	 * @param string  $taxonomy
	 */
	public static function filterTermLink($termlink, $term, $taxonomy): string
	{
		if (! $term instanceof WP_Term || $taxonomy !== UnitCategoryTaxonomy::TAXONOMY) {
			return (string) $termlink;
		}

		if (! UnitCategoryTaxonomy::isEnabled()) {
			return (string) $termlink;
		}

		$structure = UnitPermalinkSettings::resolveCategoryStructure();
		if ($structure === UnitPermalinkSettings::CAT_CATEGORY_BASE) {
			return (string) $termlink;
		}

		if ($structure === UnitPermalinkSettings::CAT_UNIT_BASE) {
			$unitSlug = UnitPostType::getPermalinkSlug();
			$url      = user_trailingslashit(home_url('/' . $unitSlug . '/' . $term->slug));

			return MultilingualBridge::localizeUrl($url, MultilingualBridge::getTermLanguage((int) $term->term_id));
		}

		if ($structure === UnitPermalinkSettings::CAT_BARE) {
			$url = user_trailingslashit(home_url('/' . $term->slug));

			return MultilingualBridge::localizeUrl($url, MultilingualBridge::getTermLanguage((int) $term->term_id));
		}

		return (string) $termlink;
	}

	public static function parseRequest(\WP $wp): void
	{
		self::maybeResolveBaseCategoryUnitRequest($wp);
		self::maybeResolveCategoryOnlyUnitRequest($wp);
		self::maybeResolveBareCategoryRequest($wp);
		self::maybeFinalizeRoutedUnitQueryVars($wp);

		$routed = isset($wp->query_vars[ self::QUERY_BEC_ROUTED ]) ? (string) $wp->query_vars[ self::QUERY_BEC_ROUTED ] : '';
		if ($routed === '') {
			return;
		}

		if ($routed === UnitPermalinkSettings::UNIT_CATEGORY_ONLY) {
			if (self::requestPathConflictsWithCoreContent($wp)) {
				self::invalidateRoutedRequest($wp);
			}

			return;
		}

		if ($routed === UnitPermalinkSettings::CAT_BARE) {
			$termSlug = isset($wp->query_vars['bec_unit_category']) ? (string) $wp->query_vars['bec_unit_category'] : '';
			if ($termSlug === '' || ! self::termExistsBySlug($termSlug)) {
				self::invalidateRoutedRequest($wp);
			}
		}
	}

	/**
	 * Resolve the target unit post during parse_request so WP_Query sees `p` before parse_query().
	 */
	private static function maybeFinalizeRoutedUnitQueryVars(\WP $wp): void
	{
		$routed = isset($wp->query_vars[ self::QUERY_BEC_ROUTED ]) ? (string) $wp->query_vars[ self::QUERY_BEC_ROUTED ] : '';
		if ($routed !== UnitPermalinkSettings::UNIT_BASE_CATEGORY && $routed !== UnitPermalinkSettings::UNIT_CATEGORY_ONLY) {
			return;
		}

		$postName = isset($wp->query_vars['name']) ? (string) $wp->query_vars['name'] : '';
		$termSlug = isset($wp->query_vars[ self::QUERY_UNIT_CATEGORY_SLUG ]) ? (string) $wp->query_vars[ self::QUERY_UNIT_CATEGORY_SLUG ] : '';
		if ($postName === '' || $termSlug === '') {
			return;
		}

		$lang = isset($wp->query_vars['lang']) ? (string) $wp->query_vars['lang'] : '';
		if ($lang === '') {
			$lang = MultilingualBridge::getCurrentLanguage();
		}
		if ($lang !== '') {
			MultilingualBridge::switchRequestLanguage($lang);
		}

		$post = MultilingualBridge::resolveUnitPostBySlug($postName, $lang !== '' ? $lang : null);
		if (! $post instanceof WP_Post) {
			return;
		}

		if (! self::postHasCategorySlug((int) $post->ID, $termSlug)) {
			return;
		}

		unset(
			$wp->query_vars['pagename'],
			$wp->query_vars['page'],
			$wp->query_vars['page_id'],
			$wp->query_vars['attachment'],
			$wp->query_vars['error']
		);

		$wp->query_vars['post_type'] = UnitPostType::POST_TYPE;
		$wp->query_vars['name']      = $post->post_name;
		$wp->query_vars['p']         = (int) $post->ID;
	}

	private static function maybeSwitchRequestLanguage(\WP $wp): void
	{
		$routed = isset($wp->query_vars[ self::QUERY_BEC_ROUTED ]) ? (string) $wp->query_vars[ self::QUERY_BEC_ROUTED ] : '';
		if ($routed === '') {
			return;
		}

		$lang = isset($wp->query_vars['lang']) ? (string) $wp->query_vars['lang'] : '';
		if ($lang === '') {
			$lang = MultilingualBridge::getCurrentLanguage();
		}

		if ($lang !== '') {
			MultilingualBridge::switchRequestLanguage($lang);
		}
	}

	private static function maybeResolveBaseCategoryUnitRequest(\WP $wp): void
	{
		if (UnitPermalinkSettings::resolveUnitStructure() !== UnitPermalinkSettings::UNIT_BASE_CATEGORY) {
			return;
		}

		$routed = isset($wp->query_vars[ self::QUERY_BEC_ROUTED ]) ? (string) $wp->query_vars[ self::QUERY_BEC_ROUTED ] : '';
		if ($routed === UnitPermalinkSettings::UNIT_BASE_CATEGORY) {
			return;
		}

		$segments = self::requestPathSegments($wp);
		if (count($segments) !== 3) {
			return;
		}

		$unitSlug = sanitize_title(UnitPostType::getPermalinkSlug());
		if ($segments[0] !== $unitSlug) {
			return;
		}

		$termSlug = sanitize_title($segments[1]);
		$postSlug = sanitize_title($segments[2]);
		if ($termSlug === '' || $postSlug === '') {
			return;
		}

		$lang = isset($wp->query_vars['lang']) ? (string) $wp->query_vars['lang'] : '';
		if ($lang === '') {
			$lang = MultilingualBridge::getCurrentLanguage();
		}
		$post = MultilingualBridge::resolveUnitPostBySlug($postSlug, $lang !== '' ? $lang : null);
		if (! $post instanceof WP_Post) {
			return;
		}

		if (! self::postHasCategorySlug((int) $post->ID, $termSlug)) {
			return;
		}

		self::applyBaseCategoryUnitQueryVars($wp, $post, $termSlug);
	}

	private static function maybeResolveCategoryOnlyUnitRequest(\WP $wp): void
	{
		if (UnitPermalinkSettings::resolveUnitStructure() !== UnitPermalinkSettings::UNIT_CATEGORY_ONLY) {
			return;
		}

		$routed = isset($wp->query_vars[ self::QUERY_BEC_ROUTED ]) ? (string) $wp->query_vars[ self::QUERY_BEC_ROUTED ] : '';
		if ($routed === UnitPermalinkSettings::UNIT_CATEGORY_ONLY) {
			return;
		}

		$segments = self::requestPathSegments($wp);
		if (count($segments) !== 2) {
			return;
		}

		$termSlug = sanitize_title($segments[0]);
		$postSlug = sanitize_title($segments[1]);
		if ($termSlug === '' || $postSlug === '') {
			return;
		}

		$combinedPath = $termSlug . '/' . $postSlug;
		if (get_page_by_path($combinedPath, OBJECT, 'page') instanceof WP_Post) {
			return;
		}

		$lang = isset($wp->query_vars['lang']) ? (string) $wp->query_vars['lang'] : '';
		if ($lang === '') {
			$lang = MultilingualBridge::getCurrentLanguage();
		}
		$post = MultilingualBridge::resolveUnitPostBySlug($postSlug, $lang !== '' ? $lang : null);
		if (! $post instanceof WP_Post) {
			return;
		}

		if (! self::postHasCategorySlug((int) $post->ID, $termSlug)) {
			return;
		}

		self::applyCategoryOnlyUnitQueryVars($wp, $post, $termSlug);
	}

	private static function maybeResolveBareCategoryRequest(\WP $wp): void
	{
		if (UnitPermalinkSettings::resolveCategoryStructure() !== UnitPermalinkSettings::CAT_BARE) {
			return;
		}

		$routed = isset($wp->query_vars[ self::QUERY_BEC_ROUTED ]) ? (string) $wp->query_vars[ self::QUERY_BEC_ROUTED ] : '';
		if ($routed === UnitPermalinkSettings::CAT_BARE) {
			return;
		}

		$endpoint = self::resolveBareCategoryEndpointFromPath($wp);
		if ($endpoint === null) {
			return;
		}

		if (self::bareCategoryTermConflictsWithCoreContent($endpoint['term'])) {
			return;
		}

		if (! self::termExistsBySlug($endpoint['term'])) {
			return;
		}

		self::applyBareCategoryQueryVars($wp, $endpoint['term']);

		if (isset($endpoint['paged'])) {
			$wp->query_vars['paged'] = $endpoint['paged'];
		}

		if (isset($endpoint['feed'])) {
			$wp->query_vars['feed'] = $endpoint['feed'];
		}

		if (! empty($endpoint['embed'])) {
			$wp->query_vars['embed'] = 'true';
		}
	}

	/**
	 * Parse bare category archive paths that core rewrite rules may have claimed first.
	 *
	 * @return array{term:string,paged?:int,feed?:string,embed?:bool}|null
	 */
	private static function resolveBareCategoryEndpointFromPath(\WP $wp): ?array
	{
		global $wp_rewrite;

		if (! $wp_rewrite instanceof \WP_Rewrite) {
			return null;
		}

		$segments = self::requestPathSegments($wp);
		$count    = count($segments);

		if ($count === 1) {
			$termSlug = $segments[0];
			if ($termSlug === '') {
				return null;
			}

			return ['term' => $termSlug];
		}

		if ($count === 2) {
			$termSlug = $segments[0];
			if ($termSlug === '') {
				return null;
			}

			if ($segments[1] === 'embed') {
				return [
					'term'  => $termSlug,
					'embed' => true,
				];
			}

			$feedNames = array_map('sanitize_title', array_map('strval', (array) $wp_rewrite->feeds));
			if (in_array($segments[1], $feedNames, true)) {
				return [
					'term' => $termSlug,
					'feed' => $segments[1],
				];
			}

			return null;
		}

		if ($count !== 3) {
			return null;
		}

		$termSlug         = $segments[0];
		$paginationBase   = sanitize_title((string) $wp_rewrite->pagination_base);
		$feedBase         = sanitize_title((string) $wp_rewrite->feed_base);
		$middleSegment    = $segments[1];
		$trailingSegment  = $segments[2];

		if ($termSlug === '') {
			return null;
		}

		if ($middleSegment === $paginationBase && ctype_digit($trailingSegment)) {
			$paged = (int) $trailingSegment;
			if ($paged < 1) {
				return null;
			}

			return [
				'term'  => $termSlug,
				'paged' => $paged,
			];
		}

		$feedNames = array_map('sanitize_title', array_map('strval', (array) $wp_rewrite->feeds));
		if ($middleSegment === $feedBase && in_array($trailingSegment, $feedNames, true)) {
			return [
				'term' => $termSlug,
				'feed' => $trailingSegment,
			];
		}

		return null;
	}

	private static function bareCategoryTermConflictsWithCoreContent(string $termSlug): bool
	{
		$termSlug = sanitize_title($termSlug);
		if ($termSlug === '') {
			return true;
		}

		if (get_page_by_path($termSlug, OBJECT, 'page') instanceof WP_Post) {
			return true;
		}

		if (get_page_by_path($termSlug, OBJECT, 'post') instanceof WP_Post) {
			return true;
		}

		return false;
	}

	/**
	 * @return array<int, string>
	 */
	private static function requestPathSegments(\WP $wp): array
	{
		$path = isset($wp->request) ? trim((string) $wp->request, '/') : '';

		if ($path === '' && isset($wp->query_vars['pagename']) && is_string($wp->query_vars['pagename'])) {
			$path = trim($wp->query_vars['pagename'], '/');
		}

		if ($path === '' && isset($_SERVER['REQUEST_URI'])) {
			$path = trim((string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
			$homePath = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
			if ($homePath !== '' && str_starts_with($path, $homePath . '/')) {
				$path = trim(substr($path, strlen($homePath)), '/');
			} elseif ($homePath !== '' && $path === $homePath) {
				$path = '';
			}
		}

		if ($path === '') {
			return [];
		}

		$segments = array_values(array_filter(
			explode('/', $path),
			static fn (string $segment): bool => $segment !== ''
		));

		$segments = array_map('sanitize_title', $segments);

		if (
			$segments !== []
			&& MultilingualBridge::isActive()
			&& MultilingualBridge::usesDirectoryLanguageUrls()
			&& MultilingualBridge::isLanguageSlug($segments[0])
		) {
			array_shift($segments);
		}

		return $segments;
	}

	private static function applyBaseCategoryUnitQueryVars(\WP $wp, WP_Post $post, string $termSlug): void
	{
		unset(
			$wp->query_vars['pagename'],
			$wp->query_vars['page'],
			$wp->query_vars['page_id'],
			$wp->query_vars['attachment'],
			$wp->query_vars['error']
		);

		$wp->query_vars['post_type'] = UnitPostType::POST_TYPE;
		$wp->query_vars['name']      = $post->post_name;
		$wp->query_vars['p']         = (int) $post->ID;
		$wp->query_vars[ self::QUERY_UNIT_CATEGORY_SLUG ] = $termSlug;
		$wp->query_vars[ self::QUERY_BEC_ROUTED ]          = UnitPermalinkSettings::UNIT_BASE_CATEGORY;
	}

	private static function applyCategoryOnlyUnitQueryVars(\WP $wp, WP_Post $post, string $termSlug): void
	{
		unset(
			$wp->query_vars['pagename'],
			$wp->query_vars['page'],
			$wp->query_vars['page_id'],
			$wp->query_vars['attachment'],
			$wp->query_vars['error']
		);

		$wp->query_vars['post_type'] = UnitPostType::POST_TYPE;
		$wp->query_vars['name']      = $post->post_name;
		$wp->query_vars['p']         = (int) $post->ID;
		$wp->query_vars[ self::QUERY_UNIT_CATEGORY_SLUG ] = $termSlug;
		$wp->query_vars[ self::QUERY_BEC_ROUTED ]          = UnitPermalinkSettings::UNIT_CATEGORY_ONLY;
	}

	private static function applyBareCategoryQueryVars(\WP $wp, string $termSlug): void
	{
		unset(
			$wp->query_vars['pagename'],
			$wp->query_vars['name'],
			$wp->query_vars['page'],
			$wp->query_vars['page_id'],
			$wp->query_vars['attachment'],
			$wp->query_vars['error']
		);

		$wp->query_vars['bec_unit_category']      = $termSlug;
		$wp->query_vars[ self::QUERY_BEC_ROUTED ] = UnitPermalinkSettings::CAT_BARE;
	}

	public static function onWp(): void
	{
		if (is_admin()) {
			return;
		}

		global $wp_query;
		if (! $wp_query instanceof WP_Query) {
			return;
		}

		$routed = $wp_query->get(self::QUERY_BEC_ROUTED);
		if (! is_string($routed) || $routed === '') {
			return;
		}

		if ($routed !== UnitPermalinkSettings::UNIT_BASE_CATEGORY && $routed !== UnitPermalinkSettings::UNIT_CATEGORY_ONLY) {
			return;
		}

		$postId = (int) $wp_query->get('p');
		if ($postId < 1) {
			return;
		}

		$post = get_post($postId);
		if (! $post instanceof WP_Post || $post->post_type !== UnitPostType::POST_TYPE) {
			return;
		}

		$termSlug = $wp_query->get(self::QUERY_UNIT_CATEGORY_SLUG);
		if (! is_string($termSlug) || $termSlug === '' || ! self::postHasCategorySlug($postId, $termSlug)) {
			return;
		}

		$wp_query->posts             = [ $post ];
		$wp_query->post              = $post;
		$wp_query->post_count        = 1;
		$wp_query->found_posts       = 1;
		$wp_query->max_num_pages     = 1;
		$wp_query->queried_object    = $post;
		$wp_query->queried_object_id = $postId;
		$wp_query->is_single         = true;
		$wp_query->is_singular       = true;
		$wp_query->is_home           = false;
		$wp_query->is_archive        = false;
		$wp_query->is_404            = false;

		if (! headers_sent()) {
			status_header(200);
		}
	}

	public static function preGetPosts(WP_Query $query): void
	{
		if (is_admin() || ! $query->is_main_query()) {
			return;
		}

		$routed = $query->get(self::QUERY_BEC_ROUTED);
		if (! is_string($routed) || $routed === '') {
			return;
		}

		$lang = MultilingualBridge::getRequestLanguage($query);
		if ($lang !== '') {
			MultilingualBridge::switchRequestLanguage($lang);
		}

		if ($routed === UnitPermalinkSettings::UNIT_BASE_CATEGORY || $routed === UnitPermalinkSettings::UNIT_CATEGORY_ONLY) {
			$postName = $query->get('name');
			$termSlug = $query->get(self::QUERY_UNIT_CATEGORY_SLUG);
			if (! is_string($postName) || $postName === '' || ! is_string($termSlug) || $termSlug === '') {
				$query->set_404();
				status_header(404);

				return;
			}

			$post = MultilingualBridge::resolveUnitPostBySlug($postName, $lang !== '' ? $lang : null);
			if (! $post instanceof WP_Post) {
				$query->set_404();
				status_header(404);

				return;
			}

			if (! self::postHasCategorySlug((int) $post->ID, $termSlug)) {
				$query->set_404();
				status_header(404);

				return;
			}

			self::configureSingleUnitQuery($query, $post);

			return;
		}

		if ($routed === UnitPermalinkSettings::CAT_UNIT_BASE || $routed === UnitPermalinkSettings::CAT_BARE) {
			$termSlug = $query->get('bec_unit_category');
			if (! is_string($termSlug) || $termSlug === '') {
				$query->set_404();
				status_header(404);

				return;
			}

			if (
				($routed === UnitPermalinkSettings::CAT_BARE || $routed === UnitPermalinkSettings::CAT_UNIT_BASE)
				&& self::termExistsBySlug($termSlug)
			) {
				$query->set('taxonomy', UnitCategoryTaxonomy::TAXONOMY);
				$query->set('term', $termSlug);

				return;
			}

			if ($routed === UnitPermalinkSettings::CAT_UNIT_BASE && self::resolveUnitStructureAllowsTwoSegmentFallback()) {
				$post = MultilingualBridge::resolveUnitPostBySlug($termSlug, $lang !== '' ? $lang : null);
				if ($post instanceof WP_Post) {
					self::configureSingleUnitQuery($query, $post);
					unset($query->query_vars['bec_unit_category']);

					return;
				}
			}

			if (! self::termExistsBySlug($termSlug)) {
				$query->set_404();
				status_header(404);
			}
		}
	}

	private static function configureSingleUnitQuery(WP_Query $query, WP_Post $post): void
	{
		$query->set('post_type', UnitPostType::POST_TYPE);
		$query->set('page', '');
		$query->set('pagename', '');
		$query->set('name', $post->post_name);
		$query->set('p', (int) $post->ID);
		$query->set('posts_per_page', 1);
		$query->is_single   = true;
		$query->is_singular = true;
		$query->is_home     = false;
		$query->is_archive  = false;
		$query->is_404      = false;
	}

	private static function resolveUnitStructureAllowsTwoSegmentFallback(): bool
	{
		$structure = UnitPermalinkSettings::resolveUnitStructure();

		return in_array($structure, [UnitPermalinkSettings::UNIT_BASE, UnitPermalinkSettings::UNIT_BASE_CATEGORY], true);
	}

	private static function invalidateRoutedRequest(\WP $wp): void
	{
		unset(
			$wp->query_vars[ self::QUERY_BEC_ROUTED ],
			$wp->query_vars[ self::QUERY_UNIT_CATEGORY_SLUG ],
			$wp->query_vars['bec_unit_category'],
			$wp->query_vars['name'],
			$wp->query_vars['post_type'],
			$wp->query_vars[ UnitPostType::POST_TYPE ]
		);
	}

	private static function requestPathConflictsWithCoreContent(\WP $wp): bool
	{
		$termSlug = isset($wp->query_vars[ self::QUERY_UNIT_CATEGORY_SLUG ]) ? sanitize_title((string) $wp->query_vars[ self::QUERY_UNIT_CATEGORY_SLUG ]) : '';
		$postSlug = isset($wp->query_vars['name']) ? sanitize_title((string) $wp->query_vars['name']) : '';

		if ($termSlug === '' || $postSlug === '') {
			return true;
		}

		$combined = $termSlug . '/' . $postSlug;

		return get_page_by_path($combined, OBJECT, 'page') instanceof WP_Post;
	}

	private static function termExistsBySlug(string $slug): bool
	{
		$term = get_term_by('slug', sanitize_title($slug), UnitCategoryTaxonomy::TAXONOMY);

		return $term instanceof WP_Term;
	}

	private static function postHasCategorySlug(int $postId, string $termSlug): bool
	{
		$terms = get_the_terms($postId, UnitCategoryTaxonomy::TAXONOMY);
		if (! is_array($terms)) {
			return false;
		}

		$termSlug = sanitize_title($termSlug);
		foreach ($terms as $term) {
			if ($term instanceof WP_Term && $term->slug === $termSlug) {
				return true;
			}
		}

		return false;
	}

	private static function resolvePrimaryTermForPost(int $postId): ?WP_Term
	{
		$terms = get_the_terms($postId, UnitCategoryTaxonomy::TAXONOMY);
		if (! is_array($terms) || $terms === []) {
			return null;
		}

		$candidates = array_values(array_filter(
			$terms,
			static fn ($term): bool => $term instanceof WP_Term
		));

		if ($candidates === []) {
			return null;
		}

		/**
		 * Choose which unit category term appears in unit permalinks when multiple are assigned.
		 *
		 * @param WP_Term|null $term    Selected term.
		 * @param array<int, WP_Term> $terms All assigned terms.
		 * @param int $postId Unit post ID.
		 */
		$selected = apply_filters('bec_unit_permalink_primary_term', $candidates[0], $candidates, $postId);

		return $selected instanceof WP_Term ? $selected : $candidates[0];
	}
}
