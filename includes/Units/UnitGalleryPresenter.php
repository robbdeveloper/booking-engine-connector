<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units;

use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Shared unit gallery resolution for shortcodes, Elementor dynamic tags, and other consumers.
 */
final class UnitGalleryPresenter
{
	/**
	 * Resolves the target unit post ID from an explicit setting or the current loop/document post.
	 */
	public static function resolveUnitPostId(int $unitIdSetting): int
	{
		if ($unitIdSetting > 0) {
			return $unitIdSetting;
		}

		$postId = (int) \get_the_ID();
		if ($postId < 1) {
			return 0;
		}

		return \get_post_type($postId) === UnitPostType::getSlug() ? $postId : 0;
	}

	/**
	 * @param array<string, mixed> $context Passed to {@see bec_unit_gallery_attachment_ids}.
	 *
	 * @return list<int>
	 */
	public static function attachmentIds(int $unitId, int $limit, int $offset, array $context = []): array
	{
		if ($unitId < 1 || \get_post_type($unitId) !== UnitPostType::getSlug()) {
			return [];
		}

		$ids = UnitGalleryReader::attachmentIdsForUnit($unitId, $limit, $offset);
		/** @var list<int> $ids */
		$ids = (array) \apply_filters('bec_unit_gallery_attachment_ids', $ids, $unitId, $context);

		return $ids;
	}

	/**
	 * Gallery rows for Elementor gallery controls ({@see \Elementor\Modules\DynamicTags\Module::GALLERY_CATEGORY}).
	 *
	 * Returns attachment IDs only (Elementor resolves URLs from the media library).
	 *
	 * @param array<string, mixed> $context Passed to gallery filters.
	 *
	 * @return list<array{id: int}>
	 */
	public static function elementorGalleryRows(int $unitId, int $limit, int $offset, array $context = []): array
	{
		$ids = self::attachmentIds($unitId, $limit, $offset, $context);
		if ($ids === []) {
			return [];
		}

		\_prime_post_caches($ids, false, true);

		$rows = [];
		foreach ($ids as $id) {
			if ($id < 1) {
				continue;
			}
			$post = \get_post($id);
			if (! $post || ! \wp_attachment_is_image($post)) {
				continue;
			}
			$rows[] = ['id' => $id];
		}

		/** @var list<array{id: int}> $rows */
		$rows = (array) \apply_filters('bec_unit_gallery_elementor_rows', $rows, $unitId, $context);

		return $rows;
	}

	/**
	 * @param array<string, mixed> $context Passed to {@see bec_unit_gallery_items}.
	 *
	 * @return list<array{id: int, url: string, alt: string, width: int, height: int}>
	 */
	public static function galleryItems(int $unitId, int $limit, int $offset, string $size, array $context = []): array
	{
		$ids = self::attachmentIds($unitId, $limit, $offset, $context);
		if ($ids === []) {
			return [];
		}

		$size  = $size !== '' ? $size : 'large';
		$items = UnitGalleryItemResolver::resolve($ids, $size);
		/** @var list<array{id: int, url: string, alt: string, width: int, height: int}> $items */
		$items = (array) \apply_filters('bec_unit_gallery_items', $items, $unitId, $context);

		return $items;
	}
}
