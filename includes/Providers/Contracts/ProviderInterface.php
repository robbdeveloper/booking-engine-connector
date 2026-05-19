<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Contracts;

use BookingEngineConnector\Providers\Auth\CredentialField;

/**
 * Booking provider abstraction (Kross, future engines).
 */
interface ProviderInterface
{
	public function getSlug(): string;

	/**
	 * Declarative credential field definitions for admin UI and validation.
	 *
	 * @return list<CredentialField>|array<int, array<string, mixed>>
	 */
	public function getCredentialSchema(): array;

	public function validateCredentials(): bool;

	/**
	 * Fetch remote inventory metadata for mapping/sync (not implemented in stub providers).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchRemoteUnits(): array;

	/**
	 * Canonical unit fields (name, address, geo, occupancy, check-in/out, rooms, bathrooms, description, m², amenities, gallery).
	 * Keys must be {@see \BookingEngineConnector\Units\CoreUnitSemantic} constants.
	 *
	 * @param array<string, mixed> $row Normalised remote row (after {@see bec_sync_remote_unit}).
	 *
	 * @return array<string, mixed>
	 */
	public function extractCoreUnitFields(array $row): array;

	/**
	 * Declarative mapping from a normalised remote unit row to post meta keys (per provider).
	 * Used during sync and to render editable fields in the unit editor.
	 *
	 * @return list<\BookingEngineConnector\Sync\UnitSyncFieldDefinition>
	 */
	public function getUnitSyncFieldDefinitions(): array;

	/**
	 * When true, the search layer collects one age per child (`bec_child_age[]`) and passes
	 * `children_ages` in the provider search context (used e.g. for Kross checkout `guests_rooms`).
	 *
	 * Only applies when {@see getSearchGuestFieldMode()} is {@see SearchGuestFieldMode::BREAKDOWN}
	 * (adults + children); in {@see SearchGuestFieldMode::TOTAL} mode the form does not show child age fields.
	 */
	public function requiresChildrenAges(): bool;

	/**
	 * How the search bar collects guest counts: {@see SearchGuestFieldMode::BREAKDOWN} (adults + children, optional ages)
	 * or {@see SearchGuestFieldMode::TOTAL} (single pax count).
	 */
	public function getSearchGuestFieldMode(): string;

	/**
	 * Price/availability quote for one remote unit and a search context.
	 *
	 * @param array<string, mixed> $searchContext Dates, guests, and provider-specific params.
	 */
	public function getQuoteForUnit(string $remoteUnitId, array $searchContext): mixed;

	/**
	 * External checkout for this unit and stay, or null if not supported.
	 *
	 * Return GET navigation: `['url' => fullUrl, 'label' => optional]` (default).
	 * Return POST submission: `['url' => actionUrl, 'method' => 'post', 'post_fields' => [...], 'label' => optional]`.
	 * The active theme/shortcode renders a link or a form accordingly.
	 *
	 * @param array<string, mixed> $searchContext Same shape as for quotes (checkin, checkout, adults, children, optional children_ages).
	 *
	 * @return array<string, mixed>|null
	 */
	public function buildCheckoutUrl(string $remoteUnitId, array $searchContext): ?array;

	/**
	 * Map of provider-specific shortcode keys to renderer callables used by `[bec_unit_info]`.
	 *
	 * Each renderer receives:
	 *   array $syncPayload  Decoded `bec_sync_payload` (normalised remote row, with `raw`)
	 *   int   $postId       bec_unit post ID
	 *   array $atts         Pass-through shortcode attributes (minus key, unit_id, default)
	 *   array $context      ['provider' => slug, 'locale' => 'en', ...]
	 *
	 * @return array<string, callable>
	 */
	public function getUnitInfoRenderers(): array;

	/**
	 * Scalar field from synced unit payload for `[bec_unit_field]`.
	 *
	 * @param array<string, mixed> $syncPayload Decoded `bec_sync_payload` (normalised remote row, with `raw`).
	 * @param array<string, string> $atts        Shortcode attributes (`field`, `type`, …).
	 * @param array<string, mixed> $context      e.g. `provider`, `locale`, `type` (`string`|`number`).
	 *
	 * @return string|int|float|null Null when the field is missing or not a valid scalar for the requested type.
	 */
	public function getUnitFieldValue(array $syncPayload, string $field, array $atts, array $context): string|int|float|null;
}
