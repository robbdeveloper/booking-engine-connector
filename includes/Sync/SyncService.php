<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Sync\CoreUnitFieldRegistry;
use BookingEngineConnector\Sync\UnitSyncFieldRegistry;
use BookingEngineConnector\Providers\Contracts\ProviderErrorCategory;
use BookingEngineConnector\Providers\Contracts\ProviderException;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Units\CoreUnitSemantic;

/**
 * Maps remote inventory rows to Unit posts (meta, title, content). Hooks: bec_before_unit_sync, bec_after_unit_sync; filters: bec_sync_remote_unit, bec_sync_unit_title, bec_sync_unit_content, bec_sync_unit_post_data, bec_sync_unit_resolve_post_statuses.
 */
final class SyncService
{
	/**
	 * Full sync: fetch remote units, upsert posts. Uses {@see SyncLock}.
	 *
	 * @param SyncProgressReporter|null $progress Optional UI progress for manual runs.
	 * @param string|null               $manualRunId When set (valid admin AJAX id), uses {@see SyncLock::acquireManual()}; otherwise cron-style lock.
	 * @return array{created:int, updated:int, skipped:int, errors: list<string>}
	 */
	public function syncAll(?SyncProgressReporter $progress = null, ?string $manualRunId = null): array
	{
		if ($progress !== null) {
			$progress->running(\__('Starting sync…', 'booking-engine-connector'));
			$progress->addLine(\__('Acquiring sync lock…', 'booking-engine-connector'));
		}

		$manualSanitized = null;
		if ($manualRunId !== null && SyncProgressReporter::isValidRunId($manualRunId)) {
			$manualSanitized = SyncProgressReporter::sanitizeRunId($manualRunId);
		}

		$userId = (int) \get_current_user_id();

		if ($manualSanitized !== null) {
			$lockOk = SyncLock::acquireManual($userId, $manualSanitized);
		} else {
			$lockOk = SyncLock::acquireCron();
		}

		if (! $lockOk) {
			$out = [
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'errors'  => [\__('Another sync is already running.', 'booking-engine-connector')],
			];
			if ($progress !== null) {
				$progress->addLine((string) ( $out['errors'][0] ?? '' ));
				$progress->done($out);
			}

			return $out;
		}

		$out = [
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => [],
		];

		try {
			if ($progress !== null) {
				$progress->addLine(\__('Lock acquired.', 'booking-engine-connector'));
			}

			$provider = ProviderRegistry::getProvider();
			if (! $provider->validateCredentials()) {
				$out['errors'][] = \__('Provider credentials are incomplete.', 'booking-engine-connector');
				if ($progress !== null) {
					$progress->addLine((string) ( $out['errors'][0] ?? '' ));
					$progress->done($out);
				}

				return $out;
			}

			$slug = $provider->getSlug();

			if ($progress !== null) {
				$progress->addLine(\__('Fetching remote units…', 'booking-engine-connector'));
			}

			try {
				$remote = $provider->fetchRemoteUnits();
			} catch (ProviderException $e) {
				$out['errors'][] = $e->getMessage();
				if ($progress !== null) {
					$progress->addLine($e->getMessage());
					$progress->done($out);
				}

				return $out;
			}

			$rowList = $this->normalizeRemoteUnitRows($remote);

			UnitCategorySync::syncUniqueDescriptorsFromRows($slug, $rowList);

			$total = \count($rowList);
			if ($progress !== null) {
				$progress->setCounters(0, $total);
				if ($total === 0) {
					$progress->addLine(\__('No remote unit rows returned.', 'booking-engine-connector'));
				} else {
					$progress->addLine(
						\sprintf(
							/* translators: %d: number of remote rows to process */
							\_n('%d remote unit row to process.', '%d remote unit rows to process.', $total, 'booking-engine-connector'),
							$total
						)
					);
				}
			}

			$index = 0;
			foreach ($rowList as $row) {
				SyncLock::refresh();

				++$index;
				$row = (array) \apply_filters('bec_sync_remote_unit', $row, $slug);

				$externalId = (string) ($row['external_id'] ?? '');
				if ($externalId === '') {
					++$out['skipped'];
					if ($progress !== null) {
						$progress->setCounters($index, $total);
						$progress->addLine(
							\sprintf(
								/* translators: 1: current index, 2: total rows */
								\__('Row %1$d/%2$d: skipped (no external ID).', 'booking-engine-connector'),
								$index,
								$total
							)
						);
					}

					continue;
				}

				if ($progress !== null) {
					$label = $this->resolveRowTitleForProgress($slug, $row);
					$progress->setCounters($index, $total);
					$progress->addLine(
						\sprintf(
							/* translators: 1: current index, 2: total rows, 3: unit title */
							\__('Processing %1$d/%2$d: %3$s', 'booking-engine-connector'),
							$index,
							$total,
							$label
						)
					);
				}

				try {
					$pack    = $this->upsertFromRemoteRow($externalId, $slug, $row, null, false);
					$result = $pack['result'];
					if ($result === 'created') {
						++$out['created'];
						if ($progress !== null) {
							$progress->addLine(
								\sprintf(
									/* translators: %s: external id */
									\__('Created unit for external ID %s.', 'booking-engine-connector'),
									$externalId
								)
							);
						}
					} elseif ($result === 'updated') {
						++$out['updated'];
						if ($progress !== null) {
							$progress->addLine(
								\sprintf(
									/* translators: %s: external id */
									\__('Updated unit for external ID %s.', 'booking-engine-connector'),
									$externalId
								)
							);
						}
					} else {
						++$out['skipped'];
						if ($progress !== null) {
							$progress->addLine(
								\sprintf(
									/* translators: %s: external id */
									\__('Skipped external ID %s.', 'booking-engine-connector'),
									$externalId
								)
							);
						}
					}
				} catch (\Throwable $e) {
					$out['errors'][] = $externalId . ': ' . $e->getMessage();
					if ($progress !== null) {
						$progress->addLine($externalId . ': ' . $e->getMessage());
					}
				}
			}

			\update_option('bec_sync_last_run_at', \current_time('mysql'), false);
		} finally {
			if ($manualSanitized !== null) {
				SyncLock::releaseManual($userId, $manualSanitized);
			} else {
				SyncLock::releaseCron();
			}
		}

		if ($progress !== null) {
			$progress->done($out);
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
		$postId = MultilingualBridge::resolveCanonicalPostId($postId);
		if ($postId < 1) {
			throw new ProviderException(
				\__('Invalid unit post.', 'booking-engine-connector'),
				ProviderErrorCategory::VALIDATION
			);
		}

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

		$this->upsertFromRemoteRow($externalId, $slug, $match, $postId, false);
	}

	/**
	 * @param iterable<mixed> $remote
	 * @return list<array<string, mixed>>
	 */
	public function normalizeRemoteUnitRows(iterable $remote): array
	{
		$rowList = [];
		foreach ($remote as $row) {
			if (! \is_array($row)) {
				continue;
			}
			$rowList[] = $row;
		}

		return $rowList;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{result: 'created'|'updated'|'skipped', deferred_gallery: array<string, mixed>|null, post_id: int}
	 */
	public function upsertRemoteRowForManualBatch(string $providerSlug, array $row, bool $deferGallery): array
	{
		$externalId = (string) ($row['external_id'] ?? '');
		if ($externalId === '') {
			return [
				'result'            => 'skipped',
				'deferred_gallery'  => null,
				'post_id'           => 0,
			];
		}

		return $this->upsertFromRemoteRow($externalId, $providerSlug, $row, null, $deferGallery);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{result: 'created'|'updated'|'skipped', deferred_gallery: array<string, mixed>|null}
	 */
	private function upsertFromRemoteRow(string $externalId, string $providerSlug, array $row, ?int $knownPostId = null, bool $deferGallery = false): array
	{
		$postId = $knownPostId ?? $this->findPostIdByExternal($externalId, $providerSlug);

		$syncEnabled = $postId ? (bool) \get_post_meta($postId, 'bec_sync_enabled', true) : true;
		if ($postId && ! $syncEnabled) {
			return [
				'result'            => 'skipped',
				'deferred_gallery'  => null,
				'post_id'           => $postId,
			];
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

		\update_post_meta($newId, 'bec_sync_payload', $row);

		CoreUnitFieldRegistry::applyFromProviderRow($newId, $providerSlug, $row, $deferGallery);
		UnitSyncFieldRegistry::applyFromRemoteRow($newId, $providerSlug, $row);

		\do_action('bec_after_unit_sync', $newId, $providerSlug, $row);

		$deferredGallery = null;
		if ($deferGallery) {
			$deferredGallery = $this->maybeDeferredRemoteGalleryPayload($providerSlug, $row);
		}

		return [
			'result'            => $postId ? 'updated' : 'created',
			'deferred_gallery'  => $deferredGallery,
			'post_id'           => $newId,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null Normalised remote gallery payload for {@see \BookingEngineConnector\Media\RemoteGalleryImporter::importFromRemotePayloadResumable()}.
	 */
	private function maybeDeferredRemoteGalleryPayload(string $providerSlug, array $row): ?array
	{
		$providerInstance = ProviderRegistry::getProvider($providerSlug);
		$coreData         = $providerInstance->extractCoreUnitFields($row);
		$coreData         = \apply_filters('bec_core_unit_fields', \is_array($coreData) ? $coreData : [], $providerSlug, $row);
		if (! \is_array($coreData) || ! isset($coreData[ CoreUnitSemantic::GALLERY ])) {
			return null;
		}
		$g = $coreData[ CoreUnitSemantic::GALLERY ];
		if (! \is_array($g)) {
			return null;
		}

		$hasItems = isset($g['items']) && \is_array($g['items']) && $g['items'] !== [];
		$hasUrls  = isset($g['urls']) && \is_array($g['urls']) && $g['urls'] !== [];
		if ($hasItems || $hasUrls) {
			return $g;
		}

		$keys   = \array_keys($g);
		$isList = $keys === \range(0, \count($keys) - 1);
		if ($isList && $g !== []) {
			foreach ($g as $x) {
				if (! \is_string($x) || ! $this->stringLooksLikeHttpUrl($x)) {
					return null;
				}
			}

			return [ 'urls' => \array_values($g) ];
		}

		return null;
	}

	private function stringLooksLikeHttpUrl(string $s): bool
	{
		$s = \trim($s);

		return $s !== '' && ( \str_starts_with($s, 'http://') || \str_starts_with($s, 'https://') );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function resolveRowTitleForProgress(string $providerSlug, array $row): string
	{
		$externalId = (string) ($row['external_id'] ?? '');

		$providerInstance = ProviderRegistry::getProvider($providerSlug);
		$coreData         = $providerInstance->extractCoreUnitFields($row);
		$coreData         = \apply_filters('bec_core_unit_fields', \is_array($coreData) ? $coreData : [], $providerSlug, $row);
		$nameDefault      = '';
		if (\is_array($coreData) && isset($coreData[ CoreUnitSemantic::NAME ])) {
			$nameDefault = (string) $coreData[ CoreUnitSemantic::NAME ];
		}

		$title = (string) \apply_filters(
			'bec_sync_unit_title',
			$nameDefault,
			$row,
			$providerSlug
		);
		if ($title === '') {
			$title = $externalId !== ''
				? \sprintf(
					/* translators: %s external id */
					\__('Unit %s', 'booking-engine-connector'),
					$externalId
				)
				: \__('(unnamed)', 'booking-engine-connector');
		}

		return $title;
	}

	private function findPostIdByExternal(string $externalId, string $providerSlug): int
	{
		/** @var list<string> $statuses */
		$statuses = \apply_filters(
			'bec_sync_unit_resolve_post_statuses',
			[ 'publish', 'draft', 'pending', 'future', 'private' ],
			$externalId,
			$providerSlug
		);
		if (! \is_array($statuses) || $statuses === []) {
			$statuses = [ 'publish', 'draft', 'pending', 'future', 'private' ];
		}

		$q = new \WP_Query(
			[
				'post_type'        => UnitPostType::getSlug(),
				'post_status'      => $statuses,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => [
					'relation' => 'AND',
					[
						'key'   => 'bec_external_id',
						'value' => $externalId,
					],
					[
						'key'   => 'bec_provider_slug',
						'value' => $providerSlug,
					],
					MultilingualBridge::canonicalOnlyMetaQueryBranch(),
				],
			]
		);

		$ids = $q->posts;

		return isset($ids[0]) ? (int) $ids[0] : 0;
	}
}
