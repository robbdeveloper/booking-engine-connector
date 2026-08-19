<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units\Completeness;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Units\AmenityItem;
use BookingEngineConnector\Units\CoreUnitMetaKeys;
use BookingEngineConnector\Units\UnitGalleryReader;

/**
 * Validates published unit posts against configured mandatory fields.
 */
final class UnitCompletenessChecker
{
	/**
	 * @return array{
	 *   post_id: int,
	 *   title: string,
	 *   external_id: string,
	 *   provider: string,
	 *   edit_url: string,
	 *   missing: list<string>,
	 *   details: array<string, string>,
	 *   field_status: array<string, string>
	 * }
	 */
	public static function checkUnit(int $postId): array
	{
		$post = \get_post($postId);
		$title = $post instanceof \WP_Post ? $post->post_title : '';

		$missing      = [];
		$details      = [];
		$fieldStatus  = [];
		$enabled      = UnitMandatoryFieldSettings::getEnabledFields();
		$galleryMin   = UnitMandatoryFieldSettings::getGalleryMinCount();

		foreach ($enabled as $fieldId) {
			$result = self::evaluateField($postId, $fieldId, $galleryMin);
			$fieldStatus[ $fieldId ] = $result['status'];

			if (! $result['complete']) {
				$missing[] = $fieldId;
				if ($result['detail'] !== '') {
					$details[ $fieldId ] = $result['detail'];
				}
			}
		}

		return [
			'post_id'      => $postId,
			'title'        => $title,
			'external_id'  => (string) \get_post_meta($postId, 'bec_external_id', true),
			'provider'     => (string) \get_post_meta($postId, 'bec_provider_slug', true),
			'edit_url'     => (string) \get_edit_post_link($postId, 'raw'),
			'missing'      => $missing,
			'details'      => $details,
			'field_status' => $fieldStatus,
		];
	}

	public static function isUnitComplete(int $postId): bool
	{
		$result = self::checkUnit($postId);

		return $result['missing'] === [];
	}

	/**
	 * @return list<int>
	 */
	public static function getScopedPostIds(): array
	{
		/**
		 * @param list<int>|null $postIds
		 */
		$filtered = \apply_filters('bec_unit_completeness_post_ids', null);
		if (\is_array($filtered)) {
			$ids = [];
			foreach ($filtered as $id) {
				$n = (int) $id;
				if ($n > 0) {
					$ids[] = $n;
				}
			}

			return \array_values(\array_unique($ids));
		}

		$queryArgs = [
			'post_type'              => UnitPostType::getSlug(),
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
		];

		$scope = UnitMandatoryFieldSettings::getSettings()['scope'];
		if ($scope === UnitMandatoryFieldSettings::SCOPE_CANONICAL) {
			$queryArgs['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => 'bec_translation_of',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => 'bec_translation_of',
					'value'   => '',
					'compare' => '=',
				],
				[
					'key'     => 'bec_translation_of',
					'value'   => '0',
					'compare' => '=',
				],
			];
		}

		$query = new \WP_Query($queryArgs);
		$ids   = $query->posts;
		if (! \is_array($ids)) {
			return [];
		}

		$out = [];
		foreach ($ids as $id) {
			$n = (int) $id;
			if ($n > 0) {
				$out[] = $n;
			}
		}

		return $out;
	}

	/**
	 * @return list<array{
	 *   post_id: int,
	 *   title: string,
	 *   external_id: string,
	 *   provider: string,
	 *   edit_url: string,
	 *   missing: list<string>,
	 *   details: array<string, string>,
	 *   field_status: array<string, string>
	 * }>
	 */
	public static function scanIncomplete(): array
	{
		$incomplete = [];

		foreach (self::getScopedPostIds() as $postId) {
			$result = self::checkUnit($postId);
			if ($result['missing'] !== []) {
				$incomplete[] = $result;
			}
		}

		return $incomplete;
	}

	/**
	 * @return array{
	 *   total: int,
	 *   incomplete: int,
	 *   incomplete_units: list<array{
	 *     post_id: int,
	 *     title: string,
	 *     external_id: string,
	 *     provider: string,
	 *     edit_url: string,
	 *     missing: list<string>,
	 *     details: array<string, string>,
	 *     field_status: array<string, string>
	 *   }>
	 * }
	 */
	public static function buildSummary(): array
	{
		$postIds    = self::getScopedPostIds();
		$total      = \count($postIds);
		$incomplete = [];

		foreach ($postIds as $postId) {
			$result = self::checkUnit($postId);
			if ($result['missing'] !== []) {
				$incomplete[] = $result;
			}
		}

		return [
			'total'              => $total,
			'incomplete'         => \count($incomplete),
			'incomplete_units'   => $incomplete,
		];
	}

	/**
	 * @return array{complete: bool, status: string, detail: string}
	 */
	private static function evaluateField(int $postId, string $fieldId, int $galleryMin): array
	{
		if ($fieldId === UnitMandatoryFieldRegistry::FIELD_FEATURED_IMAGE) {
			return self::evaluateFeaturedImage($postId);
		}

		if ($fieldId === UnitMandatoryFieldRegistry::FIELD_GALLERY) {
			return self::evaluateGallery($postId, $galleryMin);
		}

		$def = UnitMandatoryFieldRegistry::definitionFor($fieldId);
		if ($def === null || ! isset($def['semantic'])) {
			return [
				'complete' => true,
				'status'   => 'OK',
				'detail'   => '',
			];
		}

		$semantic = (string) $def['semantic'];
		$metaKey  = CoreUnitMetaKeys::metaKeyForSemantic($semantic);
		if ($metaKey === null) {
			return [
				'complete' => true,
				'status'   => 'OK',
				'detail'   => '',
			];
		}

		$type = (string) ( $def['type'] ?? 'string' );
		$raw  = \get_post_meta($postId, $metaKey, true);

		return self::evaluateMetaValue($type, $raw, $galleryMin);
	}

	/**
	 * @return array{complete: bool, status: string, detail: string}
	 */
	private static function evaluateFeaturedImage(int $postId): array
	{
		$thumbId = (int) \get_post_thumbnail_id($postId);
		if ($thumbId > 0 && \wp_attachment_is_image($thumbId)) {
			return [
				'complete' => true,
				'status'   => 'OK',
				'detail'   => '',
			];
		}

		return [
			'complete' => false,
			'status'   => 'Missing',
			'detail'   => '',
		];
	}

	/**
	 * @return array{complete: bool, status: string, detail: string}
	 */
	private static function evaluateGallery(int $postId, int $galleryMin): array
	{
		$ids   = UnitGalleryReader::attachmentIdsForUnit($postId);
		$count = \count($ids);

		if ($count >= $galleryMin) {
			return [
				'complete' => true,
				'status'   => 'OK',
				'detail'   => '',
			];
		}

		$detail = \sprintf(
			/* translators: 1: current image count, 2: required minimum */
			\__('%1$d/%2$d images', 'booking-engine-connector'),
			$count,
			$galleryMin
		);

		return [
			'complete' => false,
			'status'   => \sprintf(
				/* translators: 1: current image count, 2: required minimum */
				\__('Missing (%1$d/%2$d)', 'booking-engine-connector'),
				$count,
				$galleryMin
			),
			'detail' => $detail,
		];
	}

	/**
	 * @param mixed $raw
	 *
	 * @return array{complete: bool, status: string, detail: string}
	 */
	private static function evaluateMetaValue(string $type, $raw, int $galleryMin): array
	{
		unset($galleryMin);

		switch ($type) {
			case 'number':
			case 'bathrooms':
				if ($raw === null || $raw === '' || ! \is_numeric($raw)) {
					return self::missingStatus();
				}
				$n = $raw + 0;
				if ($n <= 0) {
					return self::missingStatus();
				}

				return self::okStatus();

			case 'amenities_json':
				if ($raw === null || $raw === '') {
					return self::missingStatus();
				}
				if (\is_string($raw)) {
					$decoded = \json_decode($raw, true);
				} elseif (\is_array($raw)) {
					$decoded = $raw;
				} else {
					return self::missingStatus();
				}
				if (! \is_array($decoded) || AmenityItem::normalizeList($decoded) === []) {
					return self::missingStatus();
				}

				return self::okStatus();

			case 'gallery_json':
				if ($raw === null || $raw === '') {
					return self::missingStatus();
				}
				$ids = UnitGalleryReader::attachmentIdsFromMeta($raw);
				if ($ids === []) {
					return self::missingStatus();
				}

				return self::okStatus();

			case 'textarea':
			case 'string':
			default:
				if ($raw === null) {
					return self::missingStatus();
				}
				if (\is_bool($raw)) {
					return $raw ? self::okStatus() : self::missingStatus();
				}
				if (\trim((string) $raw) === '') {
					return self::missingStatus();
				}

				return self::okStatus();
		}
	}

	/**
	 * @return array{complete: bool, status: string, detail: string}
	 */
	private static function okStatus(): array
	{
		return [
			'complete' => true,
			'status'   => 'OK',
			'detail'   => '',
		];
	}

	/**
	 * @return array{complete: bool, status: string, detail: string}
	 */
	private static function missingStatus(): array
	{
		return [
			'complete' => false,
			'status'   => 'Missing',
			'detail'   => '',
		];
	}
}
