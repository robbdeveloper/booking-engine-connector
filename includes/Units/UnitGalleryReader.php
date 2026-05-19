<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units;

/**
 * Reads ordered attachment IDs from canonical unit gallery meta (`bec_core_gallery`).
 */
final class UnitGalleryReader
{
	/**
	 * @return list<int>
	 */
	public static function attachmentIdsForUnit(int $unitId, int $limit = 0, int $offset = 0): array
	{
		if ($unitId < 1) {
			return [];
		}

		$metaKey = CoreUnitMetaKeys::metaKeyForSemantic(CoreUnitSemantic::GALLERY);
		if ($metaKey === null) {
			return [];
		}

		$raw = \get_post_meta($unitId, $metaKey, true);
		$ids = self::attachmentIdsFromMeta($raw);

		return self::sliceIds($ids, $limit, $offset);
	}

	/**
	 * @param mixed $raw Stored meta value (JSON string or array of IDs).
	 *
	 * @return list<int>
	 */
	public static function attachmentIdsFromMeta($raw): array
	{
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
			if (! \is_numeric($v)) {
				continue;
			}
			$n = (int) $v;
			if ($n > 0) {
				$ids[] = $n;
			}
		}

		return $ids;
	}

	/**
	 * @param list<int> $ids
	 *
	 * @return list<int>
	 */
	public static function sliceIds(array $ids, int $limit, int $offset = 0): array
	{
		if ($ids === []) {
			return [];
		}

		$offset = \max(0, $offset);
		if ($offset > 0) {
			$ids = \array_slice($ids, $offset);
		}

		if ($limit > 0) {
			$ids = \array_slice($ids, 0, $limit);
		}

		return \array_values($ids);
	}
}
