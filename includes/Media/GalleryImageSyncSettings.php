<?php

declare(strict_types=1);

namespace BookingEngineConnector\Media;

use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * WordPress options and helpers for unit gallery image filenames (Sync admin).
 */
final class GalleryImageSyncSettings
{
	/** @var string Prefix prepended to each unit gallery filename stem (e.g. hotel slug). */
	public const OPTION_FILENAME_PREFIX = 'bec_sync_gallery_image_prefix';

	/** @var string Suffix between unit stem and index (e.g. "-img"). */
	public const OPTION_FILENAME_SUFFIX = 'bec_sync_gallery_image_suffix';

	public static function getFilenamePrefix(): string
	{
		$raw = (string) \get_option(self::OPTION_FILENAME_PREFIX, '');

		return \sanitize_file_name($raw);
	}

	public static function getFilenameSuffix(): string
	{
		$raw = (string) \get_option(self::OPTION_FILENAME_SUFFIX, '');

		return \sanitize_file_name($raw);
	}

	/**
	 * WordPress-safe stem from the unit’s display name (default when prefix/suffix are empty in UI).
	 */
	public static function unitNameStemForFilename(int $unitPostId): string
	{
		$name = (string) \get_post_meta($unitPostId, 'bec_core_name', true);
		$name = \trim($name);
		if ($name === '' && $unitPostId > 0) {
			$p = \get_post($unitPostId);
			if ($p instanceof \WP_Post && $p->post_type === UnitPostType::getSlug()) {
				$name = (string) $p->post_title;
			}
		}
		$stem = \sanitize_title($name);
		if ($stem === '') {
			$stem = 'unit';
		}

		return $stem;
	}

	/**
	 * @param positive-int $index1Based
	 * @param string $extWithDot e.g. ".jpg"
	 */
	public static function composeGalleryBasename(
		int $unitPostId,
		int $index1Based,
		string $extWithDot
	): string {
		$prefix = self::getFilenamePrefix();
		$suffix = self::getFilenameSuffix();
		$stem   = self::unitNameStemForFilename($unitPostId);
		$ext    = self::normalizeExtension($extWithDot);
		$idx    = \max(1, $index1Based);

		$base = $prefix . $stem . $suffix . '-' . \sprintf('%02d', $idx) . $ext;

		return \sanitize_file_name($base);
	}

	/**
	 * @param string $extWithDot may be ".jpeg" or "jpeg"
	 */
	public static function normalizeExtension(string $extWithDot): string
	{
		$e = \strtolower(\trim($extWithDot));
		if ($e === '') {
			return '.jpg';
		}
		if ($e[0] !== '.') {
			$e = '.' . $e;
		}

		return $e;
	}
}
