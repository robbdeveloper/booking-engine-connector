<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units;

/**
 * Resolves gallery attachment IDs to front-end image objects (url, alt, dimensions).
 */
final class UnitGalleryItemResolver
{
	/**
	 * @param list<int> $attachmentIds
	 *
	 * @return list<array{id: int, url: string, alt: string, width: int, height: int}>
	 */
	public static function resolve(array $attachmentIds, string $size = 'large'): array
	{
		if ($attachmentIds === []) {
			return [];
		}

		self::primeAttachmentCaches($attachmentIds);

		$size = $size !== '' ? $size : 'large';
		$items = [];

		foreach ($attachmentIds as $id) {
			$item = self::resolveOne($id, $size);
			if ($item !== null) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * @param list<int> $attachmentIds
	 */
	private static function primeAttachmentCaches(array $attachmentIds): void
	{
		\_prime_post_caches($attachmentIds, false, true);

		if (\function_exists('update_meta_cache')) {
			\update_meta_cache('post', $attachmentIds);
		}
	}

	/**
	 * @return array{id: int, url: string, alt: string, width: int, height: int}|null
	 */
	private static function resolveOne(int $attachmentId, string $size): ?array
	{
		if ($attachmentId < 1) {
			return null;
		}

		$attachment = \get_post($attachmentId);
		if (! $attachment || ! \wp_attachment_is_image($attachment)) {
			return null;
		}

		$url = \wp_get_attachment_image_url($attachmentId, $size);
		if (! \is_string($url) || $url === '') {
			return null;
		}

		$width  = 0;
		$height = 0;
		$src    = \wp_get_attachment_image_src($attachmentId, $size);
		if (\is_array($src)) {
			$width  = isset($src[1]) ? (int) $src[1] : 0;
			$height = isset($src[2]) ? (int) $src[2] : 0;
		}

		$alt = (string) \get_post_meta($attachmentId, '_wp_attachment_image_alt', true);

		return [
			'id'     => $attachmentId,
			'url'    => $url,
			'alt'    => $alt,
			'width'  => $width,
			'height' => $height,
		];
	}
}
