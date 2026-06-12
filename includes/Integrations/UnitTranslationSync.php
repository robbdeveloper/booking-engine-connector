<?php

declare(strict_types=1);

namespace BookingEngineConnector\Integrations;

use BookingEngineConnector\Media\RemoteGalleryImporter;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Taxonomies\UnitAmenityTaxonomy;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;
use BookingEngineConnector\Units\CoreUnitMetaKeys;
use BookingEngineConnector\Units\CoreUnitSemantic;

/**
 * Auto-managed linked translation posts after canonical unit sync.
 */
final class UnitTranslationSync
{
	public static function register(): void
	{
		\add_action('bec_after_unit_sync', [self::class, 'onAfterUnitSync'], 20, 3);
		\add_action('bec_after_unit_gallery_sync', [self::class, 'onAfterGallerySync'], 10, 2);
		\add_action('wp_trash_post', [self::class, 'onTrashPost'], 10, 1);
		\add_action('before_delete_post', [self::class, 'onBeforeDeletePost'], 10, 1);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function onAfterUnitSync(int $postId, string $providerSlug, array $row): void
	{
		if (! MultilingualBridge::isFeatureEnabled()) {
			return;
		}

		if ((int) \get_post_meta($postId, MultilingualBridge::META_TRANSLATION_OF, true) > 0) {
			return;
		}

		$defaultLang = MultilingualBridge::getDefaultLanguage();
		if ($defaultLang === '') {
			return;
		}

		$currentLang = MultilingualBridge::getPostLanguage($postId);
		if ($currentLang === '' || $currentLang !== $defaultLang) {
			MultilingualBridge::setPostLanguage($postId, $defaultLang);
			$currentLang = $defaultLang;
		}

		/** @var array<string, array{title?: string, content?: string}> $strings */
		$strings = \apply_filters('bec_unit_translation_strings', [], $row, $providerSlug, $postId);
		if (! \is_array($strings)) {
			$strings = [];
		}

		$activeLangs = MultilingualBridge::getActiveLanguages();
		$translationMap = [];

		foreach ($activeLangs as $lang) {
			if ($lang === $defaultLang) {
				$translationMap[ $lang ] = $postId;
				continue;
			}

			$langStrings = isset($strings[ $lang ]) && \is_array($strings[ $lang ]) ? $strings[ $lang ] : null;
			if ($langStrings === null) {
				$existingId = MultilingualBridge::getTranslatedPostId($postId, $lang);
				if ($existingId !== null) {
					$translationMap[ $lang ] = $existingId;
				}
				continue;
			}

			$title   = isset($langStrings['title']) ? \trim((string) $langStrings['title']) : '';
			$content = isset($langStrings['content']) ? \trim((string) $langStrings['content']) : '';
			if ($title === '' && $content === '') {
				continue;
			}

			if ($title === '') {
				$title = \get_the_title($postId);
			}

			$translationId = self::upsertTranslationPost($postId, $defaultLang, $lang, $title, $content, $providerSlug, $row);
			if ($translationId > 0) {
				$translationMap[ $lang ] = $translationId;
			}
		}

		if ($translationMap !== []) {
			\update_post_meta($postId, MultilingualBridge::META_TRANSLATION_POST_IDS, $translationMap);
		}
	}

	/**
	 * @param list<int> $attachmentIds
	 */
	public static function onAfterGallerySync(int $canonicalPostId, array $attachmentIds): void
	{
		if (! MultilingualBridge::isFeatureEnabled()) {
			return;
		}

		if ((int) \get_post_meta($canonicalPostId, MultilingualBridge::META_TRANSLATION_OF, true) > 0) {
			return;
		}

		$map = \get_post_meta($canonicalPostId, MultilingualBridge::META_TRANSLATION_POST_IDS, true);
		if (! \is_array($map)) {
			return;
		}

		foreach ($map as $lang => $translationId) {
			$translationId = (int) $translationId;
			if ($translationId < 1 || $translationId === $canonicalPostId) {
				continue;
			}
			self::copySharedMetaAndMedia($canonicalPostId, $translationId);
		}
	}

	public static function onTrashPost(int $postId): void
	{
		self::cascadeToLinkedPosts($postId, 'trash');
	}

	public static function onBeforeDeletePost(int $postId): void
	{
		self::cascadeToLinkedPosts($postId, 'delete');
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function upsertTranslationPost(
		int $canonicalId,
		string $defaultLang,
		string $lang,
		string $title,
		string $content,
		string $providerSlug,
		array $row
	): int {
		$existingId = MultilingualBridge::getTranslatedPostId($canonicalId, $lang);
		if ($existingId === null) {
			$storedMap = \get_post_meta($canonicalId, MultilingualBridge::META_TRANSLATION_POST_IDS, true);
			if (\is_array($storedMap) && isset($storedMap[ $lang ])) {
				$candidate = (int) $storedMap[ $lang ];
				if ($candidate > 0 && $candidate !== $canonicalId) {
					$existingId = $candidate;
				}
			}
		}

		$postData = [
			'post_title'   => $title,
			'post_content' => \wp_kses_post($content),
			'post_status'  => 'publish',
			'post_type'    => UnitPostType::getSlug(),
		];

		if ($existingId !== null && $existingId > 0) {
			$postData['ID'] = $existingId;
			$result         = \wp_update_post(\wp_slash($postData), true);
		} else {
			$result = \wp_insert_post(\wp_slash($postData), true);
		}

		if (\is_wp_error($result)) {
			return 0;
		}

		$translationId = (int) $result;

		\update_post_meta($translationId, MultilingualBridge::META_TRANSLATION_OF, $canonicalId);
		\update_post_meta($translationId, MultilingualBridge::META_TRANSLATION_LANG, $lang);

		$nameMeta = CoreUnitMetaKeys::metaKeyForSemantic(CoreUnitSemantic::NAME);
		$descMeta = CoreUnitMetaKeys::metaKeyForSemantic(CoreUnitSemantic::DESCRIPTION);
		if (\is_string($nameMeta) && $nameMeta !== '') {
			\update_post_meta($translationId, $nameMeta, $title);
		}
		if (\is_string($descMeta) && $descMeta !== '') {
			\update_post_meta($translationId, $descMeta, $content);
		}

		MultilingualBridge::setPostLanguage($translationId, $lang);
		MultilingualBridge::linkTranslation($canonicalId, $defaultLang, $translationId, $lang);

		self::copySharedMetaAndMedia($canonicalId, $translationId);

		unset($row);

		return $translationId;
	}

	private static function copySharedMetaAndMedia(int $canonicalId, int $translationId): void
	{
		foreach (self::sharedMetaKeys() as $metaKey) {
			$value = \get_post_meta($canonicalId, $metaKey, true);
			if ($value === '' || $value === false) {
				\delete_post_meta($translationId, $metaKey);
			} else {
				\update_post_meta($translationId, $metaKey, $value);
			}
		}

		$thumbnailId = (int) \get_post_meta($canonicalId, '_thumbnail_id', true);
		if ($thumbnailId > 0) {
			\update_post_meta($translationId, '_thumbnail_id', $thumbnailId);
		} else {
			\delete_post_meta($translationId, '_thumbnail_id');
		}

		self::copyTaxonomies($canonicalId, $translationId);
	}

	/**
	 * @return list<string>
	 */
	private static function sharedMetaKeys(): array
	{
		$keys = [
			'bec_external_id',
			'bec_provider_slug',
			'bec_sync_enabled',
			'bec_last_sync_at',
			'bec_sync_payload',
			RemoteGalleryImporter::SOURCE_HASH_META,
			RemoteGalleryImporter::IMAGE_SET_HASH_META,
			RemoteGalleryImporter::IMAGE_ORDER_HASH_META,
		];

		foreach (CoreUnitSemantic::all() as $semantic) {
			if ($semantic === CoreUnitSemantic::NAME || $semantic === CoreUnitSemantic::DESCRIPTION) {
				continue;
			}
			$metaKey = CoreUnitMetaKeys::metaKeyForSemantic($semantic);
			if (\is_string($metaKey) && $metaKey !== '') {
				$keys[] = $metaKey;
			}
		}

		/** @var list<string> $filtered */
		$filtered = \apply_filters('bec_unit_translation_shared_meta_keys', $keys);

		return \is_array($filtered) ? \array_values(\array_unique(\array_map('strval', $filtered))) : $keys;
	}

	private static function copyTaxonomies(int $canonicalId, int $translationId): void
	{
		$translationLang = MultilingualBridge::getPostLanguage($translationId);
		if ($translationLang === '') {
			$translationLang = (string) \get_post_meta($translationId, MultilingualBridge::META_TRANSLATION_LANG, true);
		}

		if (UnitCategoryTaxonomy::isEnabled()) {
			$terms = \wp_get_object_terms($canonicalId, UnitCategoryTaxonomy::getSlug(), ['fields' => 'ids']);
			if (\is_array($terms) && ! \is_wp_error($terms)) {
				$mappedTerms = [];
				foreach ($terms as $termId) {
					$termId = (int) $termId;
					if ($termId < 1) {
						continue;
					}

					$assignedId = $termId;
					if ($translationLang !== '' && MultilingualBridge::isFeatureEnabled()) {
						$translatedTermId = MultilingualBridge::getTranslatedTermId($termId, $translationLang);
						if ($translatedTermId !== null) {
							$assignedId = $translatedTermId;
						}
					}

					$mappedTerms[] = $assignedId;
				}

				if ($mappedTerms !== []) {
					\wp_set_object_terms($translationId, $mappedTerms, UnitCategoryTaxonomy::getSlug(), false);
				}
			}
		}

		$terms = \wp_get_object_terms($canonicalId, UnitAmenityTaxonomy::getSlug(), ['fields' => 'ids']);
		if (\is_array($terms) && ! \is_wp_error($terms)) {
			\wp_set_object_terms($translationId, $terms, UnitAmenityTaxonomy::getSlug(), false);
		}
	}

	private static function cascadeToLinkedPosts(int $postId, string $action): void
	{
		if (! MultilingualBridge::isActive()) {
			return;
		}

		$post = \get_post($postId);
		if (! $post instanceof \WP_Post || $post->post_type !== UnitPostType::getSlug()) {
			return;
		}

		$translationOf = (int) \get_post_meta($postId, MultilingualBridge::META_TRANSLATION_OF, true);
		if ($translationOf > 0) {
			return;
		}

		$map = \get_post_meta($postId, MultilingualBridge::META_TRANSLATION_POST_IDS, true);
		if (! \is_array($map)) {
			$map = [];
		}

		$defaultLang = MultilingualBridge::getDefaultLanguage();
		foreach (MultilingualBridge::getActiveLanguages() as $lang) {
			if ($lang === $defaultLang) {
				continue;
			}
			$linkedId = isset($map[ $lang ]) ? (int) $map[ $lang ] : 0;
			if ($linkedId < 1 || $linkedId === $postId) {
				$linkedId = (int) ( MultilingualBridge::getTranslatedPostId($postId, $lang) ?? 0 );
			}
			if ($linkedId < 1 || $linkedId === $postId) {
				continue;
			}
			if ($action === 'delete') {
				\wp_delete_post($linkedId, true);
			} else {
				\wp_trash_post($linkedId);
			}
		}
	}
}
