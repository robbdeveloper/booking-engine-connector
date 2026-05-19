<?php

declare(strict_types=1);

namespace BookingEngineConnector\Media;

use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Renames existing unit gallery attachment files to match {@see GalleryImageSyncSettings} (prefix, suffix, index).
 *
 * - Uses current `bec_core_gallery` order for `-01`, `-02`, … indices.
 * - Renames in place only when the attachment is not referenced by another unit; otherwise creates a per-unit copy.
 * - Regenerates image metadata after in-place renames via {@see wp_generate_attachment_metadata()}.
 * - Updates each processed attachment’s Media Library **Title** ({@see \WP_Post::$post_title}) to match the on-disk file base name (no extension), including when the file already had the correct name but the title was still an import placeholder. Alt text, caption, and description are unchanged.
 */
final class GalleryImageFilenameRenamer
{
	/**
	 * @return array{
	 *   units: int,
	 *   renamed: int,
	 *   duplicated: int,
	 *   skipped: int,
	 *   failed: int,
	 *   errors: list<string>
	 * }
	 */
	public static function renameForAllUnits(): array
	{
		$slug = UnitPostType::getSlug();
		$q    = new \WP_Query(
			[
				'post_type'              => $slug,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
			]
		);

		$totals = [
			'units'      => 0,
			'renamed'    => 0,
			'duplicated' => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'errors'     => [],
		];

		foreach ($q->posts as $pid) {
			$pid = (int) $pid;
			if ($pid < 1) {
				continue;
			}
			$r = self::renameForUnit($pid);
			++$totals['units'];
			$totals['renamed']    += $r['renamed'];
			$totals['duplicated'] += $r['duplicated'];
			$totals['skipped']    += $r['skipped'];
			$totals['failed']     += $r['failed'];
			foreach ($r['errors'] as $err) {
				if (\count($totals['errors']) < 12) {
					$totals['errors'][] = $err;
				}
			}
		}

		return $totals;
	}

	/**
	 * @return array{
	 *   renamed: int,
	 *   duplicated: int,
	 *   skipped: int,
	 *   failed: int,
	 *   errors: list<string>
	 * }
	 */
	public static function renameForUnit(int $unitId): array
	{
		$out = [
			'renamed'    => 0,
			'duplicated' => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'errors'     => [],
		];

		if ($unitId < 1) {
			return $out;
		}

		$post = \get_post($unitId);
		if (! $post instanceof \WP_Post || $post->post_type !== UnitPostType::getSlug()) {
			$out['errors'][] = \sprintf('Invalid unit post ID %d.', $unitId);

			return $out;
		}

		$ids = self::decodeGalleryIds($unitId);
		if ($ids === []) {
			return $out;
		}

		$newGallery = $ids;
		$changed    = false;

		foreach ($ids as $i => $aid) {
			$aid = (int) $aid;
			if ($aid < 1) {
				continue;
			}

			$index1 = $i + 1;
			$r      = self::processOneAttachment($unitId, $aid, $index1);

			if ($r['new_id'] !== null && $r['new_id'] !== $aid) {
				$newGallery[ $i ] = $r['new_id'];
				$changed          = true;
			}

			if ($r['duplicated']) {
				++$out['duplicated'];
			} elseif ($r['renamed']) {
				++$out['renamed'];
			} elseif ($r['skipped']) {
				++$out['skipped'];
			}

			if ($r['failed']) {
				++$out['failed'];
			}
			if ($r['error'] !== null && \count($out['errors']) < 8) {
				$out['errors'][] = $r['error'];
			}

			if ($r['duplicated'] && (int) \get_post_thumbnail_id($unitId) === $aid) {
				\set_post_thumbnail($unitId, (int) $r['new_id']);
			}
		}

		if ($changed) {
			\update_post_meta($unitId, 'bec_core_gallery', \array_values($newGallery));
		}

		return $out;
	}

	/**
	 * @return array{
	 *   renamed: bool,
	 *   duplicated: bool,
	 *   skipped: bool,
	 *   failed: bool,
	 *   new_id: int|null,
	 *   error: string|null
	 * }
	 */
	private static function processOneAttachment(int $unitId, int $attachmentId, int $index1Based): array
	{
		$empty = [
			'renamed'    => false,
			'duplicated' => false,
			'skipped'    => false,
			'failed'     => false,
			'new_id'     => null,
			'error'      => null,
		];

		if (! \wp_attachment_is_image($attachmentId)) {
			$empty['skipped'] = true;

			return $empty;
		}

		$file = \get_attached_file($attachmentId, true);
		if (! \is_string($file) || $file === '' || ! \is_readable($file)) {
			$empty['failed'] = true;
			$empty['error']  = \sprintf('Attachment %d: missing file on disk.', $attachmentId);

			return $empty;
		}

		$ext = self::extensionFromFile($file);
		$desiredBasename = GalleryImageSyncSettings::composeGalleryBasename($unitId, $index1Based, $ext);
		$currentBasename = \basename($file);

		if (self::basenameMatchesDesired($currentBasename, $desiredBasename)) {
			self::syncAttachmentTitleToFileBasename($attachmentId);
			$empty['skipped'] = true;
			$empty['new_id']  = $attachmentId;

			return $empty;
		}

		if (! self::canRenameInPlace($attachmentId, $unitId)) {
			$newId = self::duplicateAttachmentWithBasename($attachmentId, $unitId, $desiredBasename);
			if ($newId === null) {
				return [
					'renamed'    => false,
					'duplicated' => false,
					'skipped'    => false,
					'failed'     => true,
					'new_id'     => null,
					'error'      => \sprintf('Attachment %d: could not duplicate for unit %d.', $attachmentId, $unitId),
				];
			}

			return [
				'renamed'    => false,
				'duplicated' => true,
				'skipped'    => false,
				'failed'     => false,
				'new_id'     => $newId,
				'error'      => null,
			];
		}

		$ok = self::renameAttachmentInPlace($attachmentId, $desiredBasename);
		if (! $ok) {
			return [
				'renamed'    => false,
				'duplicated' => false,
				'skipped'    => false,
				'failed'     => true,
				'new_id'     => null,
				'error'      => \sprintf('Attachment %d: rename failed.', $attachmentId),
			];
		}

		return [
			'renamed'    => true,
			'duplicated' => false,
			'skipped'    => false,
			'failed'     => false,
			'new_id'     => $attachmentId,
			'error'      => null,
		];
	}

	private static function basenameMatchesDesired(string $currentBasename, string $desiredBasename): bool
	{
		if ($currentBasename === $desiredBasename) {
			return true;
		}
		$stem = \pathinfo($desiredBasename, \PATHINFO_FILENAME);
		$ext  = \pathinfo($desiredBasename, \PATHINFO_EXTENSION);
		if ($stem !== '' && $ext !== '') {
			$scaled = $stem . '-scaled.' . $ext;
			if ($currentBasename === $scaled) {
				return true;
			}
		}

		return false;
	}

	private static function canRenameInPlace(int $attachmentId, int $unitId): bool
	{
		$owned = (int) \get_post_meta($attachmentId, RemoteGalleryImporter::GALLERY_UNIT_ID_META, true);
		if ($owned > 0 && $owned !== $unitId) {
			return false;
		}

		$n = self::countAttachmentReferencesAmongUnits($attachmentId);

		return $n <= 1;
	}

	/**
	 * Counts `bec_unit` posts that reference this attachment in gallery or featured image.
	 */
	private static function countAttachmentReferencesAmongUnits(int $attachmentId): int
	{
		$slug = UnitPostType::getSlug();
		$q    = new \WP_Query(
			[
				'post_type'      => $slug,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		$n = 0;
		foreach ($q->posts as $pid) {
			$pid = (int) $pid;
			$uses = false;
			if ((int) \get_post_thumbnail_id($pid) === $attachmentId) {
				$uses = true;
			}
			$g = (string) \get_post_meta($pid, 'bec_core_gallery', true);
			if ($g !== '') {
				$decoded = \json_decode($g, true);
				if (\is_array($decoded)) {
					foreach ($decoded as $v) {
						if (\is_numeric($v) && (int) $v === $attachmentId) {
							$uses = true;
							break;
						}
					}
				}
			}
			if ($uses) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * @return list<int>
	 */
	private static function decodeGalleryIds(int $unitId): array
	{
		$raw = \get_post_meta($unitId, 'bec_core_gallery', true);
		if (\is_string($raw) && $raw !== '') {
			$decoded = \json_decode($raw, true);
		} elseif (\is_array($raw)) {
			$decoded = $raw;
		} else {
			return [];
		}
		if (! \is_array($decoded) || $decoded === []) {
			return [];
		}
		$ids = [];
		foreach ($decoded as $v) {
			if (\is_numeric($v)) {
				$n = (int) $v;
				if ($n > 0) {
					$ids[] = $n;
				}
			}
		}

		return $ids;
	}

	private static function extensionFromFile(string $file): string
	{
		$ext = \strtolower(\pathinfo($file, \PATHINFO_EXTENSION));
		if ($ext !== '' && \strlen($ext) <= 5 && \preg_match('/^[a-z0-9]+$/', $ext) === 1) {
			return '.' . $ext;
		}

		return '.jpg';
	}

	private static function renameAttachmentInPlace(int $attachmentId, string $desiredBasename): bool
	{
		self::loadAdminFileDependencies();

		$oldFull = \get_attached_file($attachmentId, true);
		if (! \is_string($oldFull) || $oldFull === '' || ! \is_readable($oldFull)) {
			return false;
		}

		$dir = \dirname($oldFull);
		$uniqueBase = \wp_unique_filename($dir, $desiredBasename);
		if ($uniqueBase === '') {
			return false;
		}

		$newFull = $dir . '/' . $uniqueBase;
		if ($newFull === $oldFull) {
			return true;
		}

		self::deleteIntermediateFilesFromMeta($attachmentId, $oldFull);

		if (! @\rename($oldFull, $newFull)) {
			return false;
		}

		\update_attached_file($attachmentId, $newFull);

		if (\function_exists('wp_generate_attachment_metadata') && \function_exists('wp_update_attachment_metadata')) {
			\wp_update_attachment_metadata($attachmentId, \wp_generate_attachment_metadata($attachmentId, $newFull));
		}

		$h = @\hash_file('sha256', $newFull) ?: '';
		if ($h !== '') {
			\update_post_meta($attachmentId, RemoteGalleryImporter::GALLERY_FILE_HASH_META, $h);
		}

		self::syncAttachmentTitleToFileBasename($attachmentId);

		return true;
	}

	private static function deleteIntermediateFilesFromMeta(int $attachmentId, string $mainFilePath): void
	{
		$meta = \wp_get_attachment_metadata($attachmentId);
		if (! \is_array($meta) || ! isset($meta['sizes']) || ! \is_array($meta['sizes'])) {
			return;
		}
		$dir = \dirname($mainFilePath);
		foreach ($meta['sizes'] as $size) {
			if (! \is_array($size) || empty($size['file'])) {
				continue;
			}
			$p = $dir . '/' . $size['file'];
			if (\is_readable($p)) {
				@\unlink($p);
			}
		}
	}

	private static function duplicateAttachmentWithBasename(int $sourceId, int $unitId, string $desiredBasename): ?int
	{
		self::loadAdminFileDependencies();

		$file = \get_attached_file($sourceId, true);
		if (! \is_string($file) || $file === '' || ! \is_readable($file)) {
			return null;
		}

		$uploads = \wp_upload_dir();
		if (! empty($uploads['error']) || ! isset($uploads['path']) || $uploads['path'] === '') {
			return null;
		}

		$tmp = \wp_tempnam('bec-gal-rename-');
		if ($tmp === false || ! @\copy($file, $tmp) || ! \is_readable($tmp)) {
			@\unlink((string) $tmp);

			return null;
		}

		$uniqueBase   = \wp_unique_filename($uploads['path'], $desiredBasename);
		$sideloadName = $uniqueBase !== '' ? $uniqueBase : $desiredBasename;

		$arr = [ 'name' => $sideloadName, 'tmp_name' => $tmp ];
		$aid = \media_handle_sideload($arr, $unitId);
		@\unlink($tmp);

		if (\is_wp_error($aid)) {
			return null;
		}

		$aid = (int) $aid;

		$url = (string) \get_post_meta($sourceId, RemoteGalleryImporter::SOURCE_URL_META, true);
		$key = (string) \get_post_meta($sourceId, RemoteGalleryImporter::GALLERY_IMAGE_KEY_META, true);
		if ($url !== '') {
			\update_post_meta($aid, RemoteGalleryImporter::SOURCE_URL_META, \esc_url_raw(\trim($url)));
		}
		if ($key !== '') {
			\update_post_meta($aid, RemoteGalleryImporter::GALLERY_IMAGE_KEY_META, $key);
		}
		\update_post_meta($aid, RemoteGalleryImporter::GALLERY_UNIT_ID_META, (string) $unitId);

		$h = @\hash_file('sha256', (string) \get_attached_file($aid, true)) ?: '';
		if ($h !== '') {
			\update_post_meta($aid, RemoteGalleryImporter::GALLERY_FILE_HASH_META, $h);
		}

		self::syncAttachmentTitleToFileBasename($aid);

		return $aid;
	}

	/**
	 * Sets the Media Library Title ({@see \WP_Post::$post_title}) from the main file name (stem only).
	 * Strips a trailing `-scaled` segment WordPress adds to large JPEGs so the title matches the logical basename.
	 * Does not touch `_wp_attachment_image_alt`, caption, or description.
	 */
	private static function syncAttachmentTitleToFileBasename(int $attachmentId): void
	{
		if ($attachmentId < 1) {
			return;
		}
		$file = \get_attached_file($attachmentId, true);
		if (! \is_string($file) || $file === '') {
			return;
		}
		$stem = \pathinfo(\basename($file), \PATHINFO_FILENAME);
		$stem = \is_string($stem) ? \trim($stem) : '';
		if ($stem !== '' && \str_ends_with($stem, '-scaled')) {
			$stem = \substr($stem, 0, -\strlen('-scaled'));
			$stem = \trim($stem);
		}
		if ($stem === '') {
			return;
		}
		\wp_update_post([
			'ID'         => $attachmentId,
			'post_title' => \sanitize_text_field($stem),
		]);
	}

	private static function loadAdminFileDependencies(): void
	{
		if (! \function_exists('wp_tempnam')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if (! \function_exists('media_handle_sideload')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if (! \function_exists('wp_generate_attachment_metadata')) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
