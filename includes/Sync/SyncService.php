<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Sync\CoreUnitFieldRegistry;
use BookingEngineConnector\Sync\UnitSyncFieldRegistry;
use BookingEngineConnector\Providers\Contracts\ProviderErrorCategory;
use BookingEngineConnector\Providers\Contracts\ProviderException;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Units\CoreUnitSemantic;

/**
 * Maps remote inventory rows to Unit posts (meta, title, content). Hooks: bec_before_unit_sync, bec_after_unit_sync; filters: bec_sync_remote_unit, bec_sync_unit_title, bec_sync_unit_content, bec_sync_unit_post_data.
 */
final class SyncService
{
	/**
	 * Full sync: fetch remote units, upsert posts. Uses {@see SyncLock}.
	 *
	 * @return array{created:int, updated:int, skipped:int, errors: list<string>}
	 */
	public function syncAll(): array
	{
		if (! SyncLock::acquire()) {
			return [
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'errors'  => [\__('Another sync is already running.', 'booking-engine-connector')],
			];
		}

		$out = [
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => [],
		];

		try {
			$provider = ProviderRegistry::getProvider();
			if (! $provider->validateCredentials()) {
				$out['errors'][] = \__('Provider credentials are incomplete.', 'booking-engine-connector');

				return $out;
			}

			$slug = $provider->getSlug();

			try {
				$remote = $provider->fetchRemoteUnits();
			} catch (ProviderException $e) {
				$out['errors'][] = $e->getMessage();

				return $out;
			}

			foreach ($remote as $row) {
				if (! \is_array($row)) {
					continue;
				}

				$row = (array) \apply_filters('bec_sync_remote_unit', $row, $slug);

				$externalId = (string) ($row['external_id'] ?? '');
				if ($externalId === '') {
					++$out['skipped'];
					continue;
				}

				try {
					$result = $this->upsertFromRemoteRow($externalId, $slug, $row);
					if ($result === 'created') {
						++$out['created'];
					} elseif ($result === 'updated') {
						++$out['updated'];
					} else {
						++$out['skipped'];
					}
				} catch (\Throwable $e) {
					$out['errors'][] = $externalId . ': ' . $e->getMessage();
				}
			}

			\update_option('bec_sync_last_run_at', \current_time('mysql'), false);
		} finally {
			SyncLock::release();
		}

		return $out;
	}

	/**
	 * Sync one Unit post: refetch remote list and update matching row.
	 *
	 * @throws ProviderException
	 */
	public function syncPost(int $postId): void
	{
		$post = \get_post($postId);
		if (! $post || $post->post_type !== UnitPostType::getSlug()) {
			throw new ProviderException(
				\__('Invalid unit post.', 'booking-engine-connector'),
				ProviderErrorCategory::VALIDATION
			);
		}

		$externalId = (string) \get_post_meta($postId, 'bec_external_id', true);
		if ($externalId === '') {
			throw new ProviderException(
				\__('Unit has no external ID.', 'booking-engine-connector'),
				ProviderErrorCategory::VALIDATION
			);
		}

		$provider = ProviderRegistry::getProvider();
		$slug     = $provider->getSlug();

		$storedProvider = (string) \get_post_meta($postId, 'bec_provider_slug', true);
		if ($storedProvider !== '' && $storedProvider !== $slug) {
			throw new ProviderException(
				\__('Unit provider does not match the active provider.', 'booking-engine-connector'),
				ProviderErrorCategory::VALIDATION
			);
		}

		$remote = $provider->fetchRemoteUnits();
		$match  = null;
		foreach ($remote as $row) {
			if (! \is_array($row)) {
				continue;
			}
			$row = (array) \apply_filters('bec_sync_remote_unit', $row, $slug);
			if ((string) ($row['external_id'] ?? '') === $externalId) {
				$match = $row;
				break;
			}
		}

		if ($match === null) {
			throw new ProviderException(
				\__('Remote unit not found for this external ID.', 'booking-engine-connector'),
				ProviderErrorCategory::VALIDATION
			);
		}

		$this->upsertFromRemoteRow($externalId, $slug, $match, $postId);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return 'created'|'updated'|'skipped'
	 */
	private function upsertFromRemoteRow(string $externalId, string $providerSlug, array $row, ?int $knownPostId = null): string
	{
		$postId = $knownPostId ?? $this->findPostIdByExternal($externalId, $providerSlug);

		$syncEnabled = $postId ? (bool) \get_post_meta($postId, 'bec_sync_enabled', true) : true;
		if ($postId && ! $syncEnabled) {
			return 'skipped';
		}

		$providerInstance = ProviderRegistry::getProvider($providerSlug);
		$coreData         = $providerInstance->extractCoreUnitFields($row);
		$coreData         = \apply_filters('bec_core_unit_fields', is_array($coreData) ? $coreData : [], $providerSlug, $row);
		$nameDefault      = '';
		if (is_array($coreData) && isset($coreData[ CoreUnitSemantic::NAME ])) {
			$nameDefault = (string) $coreData[ CoreUnitSemantic::NAME ];
		}

		$title = (string) \apply_filters(
			'bec_sync_unit_title',
			$nameDefault,
			$row,
			$providerSlug
		);
		if ($title === '') {
			$title = \sprintf(
				/* translators: %s external id */
				\__('Unit %s', 'booking-engine-connector'),
				$externalId
			);
		}

		$contentDefault = '';
		if (is_array($coreData) && isset($coreData[ CoreUnitSemantic::DESCRIPTION ])) {
			$contentDefault = (string) $coreData[ CoreUnitSemantic::DESCRIPTION ];
		}

		$content = (string) \apply_filters(
			'bec_sync_unit_content',
			$contentDefault,
			$row,
			$providerSlug
		);

		$postData = [
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_type'    => UnitPostType::getSlug(),
			'post_content' => \wp_kses_post($content),
		];

		$postData = (array) \apply_filters('bec_sync_unit_post_data', $postData, $row, $providerSlug, $postId);

		if ($postId) {
			$postData['ID'] = $postId;
		}

		$beforePostId = $postId > 0 ? $postId : 0;
		\do_action('bec_before_unit_sync', $beforePostId, $providerSlug, $row);

		if ($postId) {
			$rid = \wp_update_post(\wp_slash($postData), true);
		} else {
			$rid = \wp_insert_post(\wp_slash($postData), true);
		}

		if (\is_wp_error($rid)) {
			throw new ProviderException(
				$rid->get_error_message(),
				ProviderErrorCategory::UNKNOWN
			);
		}

		$newId = (int) $rid;

		\update_post_meta($newId, 'bec_external_id', $externalId);
		\update_post_meta($newId, 'bec_provider_slug', $providerSlug);
		\update_post_meta($newId, 'bec_last_sync_at', \current_time('mysql'));
		if (! $postId) {
			\update_post_meta($newId, 'bec_sync_enabled', true);
		}

		$payloadJson = SyncPayloadEncoder::encode($row);
		\update_post_meta($newId, 'bec_sync_payload', $payloadJson);

		CoreUnitFieldRegistry::applyFromProviderRow($newId, $providerSlug, $row);
		UnitSyncFieldRegistry::applyFromRemoteRow($newId, $providerSlug, $row);

		\do_action('bec_after_unit_sync', $newId, $providerSlug, $row);

		return $postId ? 'updated' : 'created';
	}

	private function findPostIdByExternal(string $externalId, string $providerSlug): int
	{
		$q = new \WP_Query(
			[
				'post_type'      => UnitPostType::getSlug(),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'   => 'bec_external_id',
						'value' => $externalId,
					],
					[
						'key'   => 'bec_provider_slug',
						'value' => $providerSlug,
					],
				],
			]
		);

		$ids = $q->posts;

		return isset($ids[0]) ? (int) $ids[0] : 0;
	}
}
