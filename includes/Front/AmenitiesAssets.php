<?php

declare(strict_types=1);

namespace BookingEngineConnector\Front;

use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Registers and enqueues icon-font packs and Kross-specific amenities grid styles.
 */
final class AmenitiesAssets
{
	public static function register(): void
	{
		\add_action('wp_enqueue_scripts', [self::class, 'onEnqueue'], 5);
	}

	public static function onEnqueue(): void
	{
		if (! self::shouldPreloadKrossStack()) {
			return;
		}
		$krossPack = (string) \apply_filters('bec_kross_amenities_default_font_pack', 'font-1', 0, []);
		self::enqueueKrossStack($krossPack);
	}

	/**
	 * Called from the `amenities_grid` shortcode renderer so styles load on pages
	 * that are not pre-detected in {@see onEnqueue()}.
	 *
	 * @param int $postId `bec_unit` post ID
	 * @param array<string, string> $passThrough [bec_unit_info] atts; may include `font_pack`, `columns`, `limit`, `category`
	 */
	public static function enqueueForKross(int $postId, array $passThrough = []): void
	{
		$default = (string) \apply_filters('bec_kross_amenities_default_font_pack', 'font-1', $postId, $passThrough);
		$pack    = self::fontPackFromAttributes($passThrough, $default);
		self::enqueueKrossStack($pack);
	}

	/**
	 * @return array<string, array{rel_path: string, handle: string}>
	 */
	public static function getFontPackDefinitions(): array
	{
		$defs = [
			'font-1' => [
				'rel_path' => 'assets/fonts/amenities/font-1/style.css',
				'handle'   => 'bec-amenities-font-font-1',
			],
		];

		/**
		 * Add icon-font packs. Each `handle` must be unique.
		 *
		 * @param array<string, array{rel_path: string, handle: string}> $defs
		 */
		return (array) \apply_filters('bec_amenities_font_packs', $defs);
	}

	/**
	 * @param array<string, string> $passThrough
	 */
	public static function fontPackFromAttributes(array $passThrough, string $default): string
	{
		$raw = isset($passThrough['font_pack']) ? \trim((string) $passThrough['font_pack']) : '';
		$raw = \preg_replace('#[^a-z0-9_-]+#i', '', $raw) ?? '';
		if ($raw === '' || $raw === 'default') {
			$raw = $default;
		}
		$defs = self::getFontPackDefinitions();
		if (! isset($defs[ $raw ])) {
			$raw = $default;
		}
		if (! isset($defs[ $raw ])) {
			$raw = 'font-1';
		}

		return $raw;
	}

	private static function shouldPreloadKrossStack(): bool
	{
		if ((bool) \apply_filters('bec_enqueue_public_assets', false)) {
			return true;
		}
		if (\is_singular(UnitPostType::getSlug()) || \is_post_type_archive(UnitPostType::getSlug())) {
			return true;
		}
		$post = self::getCurrentSingularPost();
		if (! $post instanceof \WP_Post) {
			return (bool) \apply_filters('bec_enqueue_kross_amenities_assets', false, null);
		}
		$content = (string) $post->post_content;
		if (self::contentHasBecUnitInfoKey($content, 'amenities_grid')) {
			return true;
		}

		return (bool) \apply_filters('bec_enqueue_kross_amenities_assets', false, $post);
	}

	private static function getCurrentSingularPost(): ?\WP_Post
	{
		if (! \is_singular() || (function_exists('is_feed') && \is_feed())) {
			return null;
		}
		$p = \get_post();
		return $p instanceof \WP_Post ? $p : null;
	}

	private static function contentHasBecUnitInfoKey(string $content, string $keyValue): bool
	{
		if (! \str_contains($content, 'bec_unit_info') || ! \str_contains($content, $keyValue)) {
			return false;
		}
		$esc = \preg_quote($keyValue, '/');
		// e.g. [bec_unit_info key="amenities_grid" ...] or [bec_unit_info key='amenities_grid']
		if ((bool) \preg_match('/\[\s*bec_unit_info\b[^\]]*\bkey\s*=\s*["\']' . $esc . '["\'][^\]]*\]/', $content)) {
			return true;
		}

		return false;
	}

	private static function enqueueKrossStack(string $packSlug): void
	{
		$defMap  = self::getFontPackDefinitions();
		$def     = $defMap[ $packSlug ] ?? $defMap['font-1'] ?? null;
		if (! \is_array($def)) {
			return;
		}
		$fontHandle = (string) $def['handle'];
		$path       = (string) $def['rel_path'];
		$abs        = \BEC_PLUGIN_DIR . $path;
		$gridRel    = 'assets/public-amenities-kross.css';
		$gridAbs    = \BEC_PLUGIN_DIR . $gridRel;
		if (! \is_readable($abs) || ! \is_readable($gridAbs)) {
			return;
		}
		$fontVer = (string) \filemtime($abs);
		$gridVer = (string) \filemtime($gridAbs);

		\wp_register_style(
			$fontHandle,
			\BEC_PLUGIN_URL . $path,
			[],
			$fontVer
		);
		\wp_enqueue_style($fontHandle);

		\wp_register_style(
			'bec-amenities-kross-grid',
			\BEC_PLUGIN_URL . $gridRel,
			[$fontHandle],
			$gridVer
		);
		\wp_enqueue_style('bec-amenities-kross-grid');
	}
}
