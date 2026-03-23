<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

/**
 * One mapping from a normalised remote unit row to a single post meta key.
 *
 * The callable reads the provider-specific row shape (after {@see bec_sync_remote_unit}).
 *
 * @phpstan-type FieldType 'string'|'textarea'|'number'|'boolean'|'json'
 */
final class UnitSyncFieldDefinition
{
	/**
	 * @param FieldType $type
	 * @param callable(array<string, mixed>): mixed $extract
	 */
	public function __construct(
		public string $metaKey,
		public string $label,
		public string $type,
		public string $providerSlug,
		public $extract
	) {
	}
}
