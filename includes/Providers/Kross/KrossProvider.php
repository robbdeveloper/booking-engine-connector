<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Api\HttpClient;
use BookingEngineConnector\Api\HttpResponse;
use BookingEngineConnector\Fallback\FallbackSettings;
use BookingEngineConnector\Integrations\Multilingual;
use BookingEngineConnector\Providers\Contracts\BulkQuoteProviderInterface;
use BookingEngineConnector\Providers\Contracts\ProviderErrorCategory;
use BookingEngineConnector\Providers\Contracts\ProviderException;
use BookingEngineConnector\Providers\Contracts\ProviderInterface;
use BookingEngineConnector\Providers\Contracts\SearchGuestFieldMode;

/**
 * Kross API v5: room types + calendar availability (see docs/KROSS-API.md).
 */
final class KrossProvider implements ProviderInterface, BulkQuoteProviderInterface
{
	private KrossAuthenticator $authenticator;

	private KrossApiClient $api;

	public function __construct(
		?HttpClient $http = null,
		?KrossAuthenticator $authenticator = null,
		?KrossApiClient $apiClient = null
	) {
		$http                = $http ?? new HttpClient();
		$this->authenticator = $authenticator ?? new KrossAuthenticator($http);
		$this->api           = $apiClient ?? new KrossApiClient($http, $this->authenticator);
	}

	public function getSlug(): string
	{
		return 'kross';
	}

	public function getCredentialSchema(): array
	{
		return $this->authenticator->getCredentialFields();
	}

	public function validateCredentials(): bool
	{
		$h = (string) \get_option(KrossAuthenticator::OPTION_HOTEL_ID, '');
		$k = (string) \get_option(KrossAuthenticator::OPTION_API_KEY, '');
		$u = (string) \get_option(KrossAuthenticator::OPTION_USERNAME, '');
		$p = (string) \get_option(KrossAuthenticator::OPTION_PASSWORD, '');

		return $h !== '' && $k !== '' && $u !== '' && $p !== '';
	}

	/**
	 * @return list<\BookingEngineConnector\Sync\UnitSyncFieldDefinition>
	 */
	public function getUnitSyncFieldDefinitions(): array
	{
		return KrossUnitSyncFieldDefinitions::get();
	}

	public function getUnitInfoRenderers(): array
	{
		return KrossUnitInfoRenderers::get();
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	public function extractCoreUnitFields(array $row): array
	{
		return KrossCoreUnitFields::extract($row);
	}

	public function requiresChildrenAges(): bool
	{
		return false;
	}

	public function getSearchGuestFieldMode(): string
	{
		return SearchGuestFieldMode::TOTAL;
	}

	public function fetchRemoteUnits(): array
	{
		[ $decoded, $rows ] = $this->fetchRoomTypesDecodedAndRows();

		KrossBookingEngineSyncSettings::updateAvailableEnginesFromNormalizedRows($rows);

		$filtered = [];

		foreach ($rows as $row) {
			if (! \is_array($row)) {
				continue;
			}

			if (KrossBookingEngineSyncSettings::normalizedRowPassesSyncSelection($row)) {
				$filtered[] = $row;
			}
		}

		/**
		 * @param array<int, array<string, mixed>> $filtered
		 * @param array<string, mixed> $decoded
		 * @return array<int, array<string, mixed>>
		 */
		return \apply_filters('bec_provider_remote_units', $filtered, 'kross', $decoded);
	}

	/**
	 * Calls `/v5/rooms/get-room-types` and merges discovered `be_enabled` slugs into the cached catalog.
	 *
	 * @return list<string> Cached available engine slugs after merge.
	 *
	 * @throws ProviderException
	 */
	public function refreshBookingEngineOptionsFromRemote(): array
	{
		[ , $rows ] = $this->fetchRoomTypesDecodedAndRows();

		KrossBookingEngineSyncSettings::updateAvailableEnginesFromNormalizedRows($rows);

		return KrossBookingEngineSyncSettings::getCachedAvailableEngines();
	}

	/**
	 * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
	 *
	 * @throws ProviderException
	 */
	private function fetchRoomTypesDecodedAndRows(): array
	{
		$payload = (array) \apply_filters(
			'bec_kross_room_types_payload',
			[
				'with_be_info'            => true,
				'with_custom_fields'      => true,
				'with_images_full'        => true,
				'with_mandatory_services' => true,
				'with_additional_info'    => true,
				'with_long_term'          => true,
				'with_amenities'          => true,
				'with_bed_bath_details'   => true,
				'with_damage_deposit'     => true,
			]
		);

		$response = $this->api->request('GET', '/v5/rooms/get-room-types', $payload);

		$this->assertHttpOk($response);

		$decoded = KrossResponseParser::decodeBody($response->getBody());

		if (! KrossResponseParser::isSuccess($decoded)) {
			throw new ProviderException(
				self::formatEnvelopeFailure(
					\__('Kross get-room-types request was not successful.', 'booking-engine-connector'),
					$decoded
				),
				self::decodedErrorCategory($decoded)
			);
		}

		$data = KrossResponseParser::getDataPayload($decoded);
		$rows = $this->normalizeRoomTypesList($data);

		return [ $decoded, $rows ];
	}

	/**
	 * @param array<string, mixed> $searchContext Expected keys: checkin, checkout, adults, children (or date_from, date_to, guests).
	 */
	public function getQuoteForUnit(string $remoteUnitId, array $searchContext): mixed
	{
		$decoded = $this->requestCalendarBookEnvelope(
			$searchContext,
			(int) $remoteUnitId,
			$remoteUnitId
		);
		$data  = KrossResponseParser::getDataPayload($decoded);
		$quote = $this->buildQuoteForRoomType($data, $remoteUnitId);

		/**
		 * @param array<string, mixed> $quote
		 * @param array<string, mixed> $searchContext
		 */
		return \apply_filters('bec_kross_quote_result', $quote, $remoteUnitId, $searchContext, $decoded);
	}

	public function getBulkQuoteCacheKey(array $searchContext): string
	{
		$hotelId = (string) \get_option(KrossAuthenticator::OPTION_HOTEL_ID, '');
		$payload = [
			'provider' => 'kross',
			'hotel'    => $hotelId,
			'context'  => $searchContext,
		];

		return 'bec_kross_quote_bulk_' . \md5((string) \wp_json_encode($payload));
	}

	public function fetchBulkQuotes(array $searchContext): mixed
	{
		return $this->requestCalendarBookEnvelope($searchContext, 0, '');
	}

	public function quoteFromBulk(mixed $bulk, string $remoteUnitId, array $searchContext): mixed
	{
		if (! \is_array($bulk)) {
			return [
				'id_room_type' => $remoteUnitId,
				'rows'         => [],
				'available'    => false,
			];
		}

		$data  = KrossResponseParser::getDataPayload($bulk);
		$quote = $this->buildQuoteForRoomType($data, $remoteUnitId);

		return \apply_filters('bec_kross_quote_result', $quote, $remoteUnitId, $searchContext, $bulk);
	}

	/**
	 * @return array<string, mixed> Decoded JSON envelope (includes `data`).
	 */
	private function requestCalendarBookEnvelope(array $searchContext, int $restrictRoomTypeId, string $remoteUnitIdForFilter): array
	{
		$checkin  = (string) ($searchContext['checkin'] ?? $searchContext['date_from'] ?? '');
		$checkout = (string) ($searchContext['checkout'] ?? $searchContext['date_to'] ?? '');
		if ($checkin === '' || $checkout === '') {
			throw new ProviderException(
				\__('Search context must include check-in and check-out dates.', 'booking-engine-connector'),
				ProviderErrorCategory::VALIDATION
			);
		}

		$adults   = (int) ($searchContext['adults'] ?? 0);
		$children = (int) ($searchContext['children'] ?? 0);
		$guests   = (int) ($searchContext['guests'] ?? 0);
		if ($guests < 1) {
			$guests = $adults + $children;
		}
		if ($guests < 1) {
			$guests = 1;
		}
		if ($adults < 1) {
			$adults = $guests;
		}

		$childrenAges = self::resolveChildrenAges($searchContext, $children);

		$bookPayload = [
			'date_from'     => $checkin,
			'date_to'       => $checkout,
			'adults'        => $adults,
			'children_ages' => $childrenAges,
			'with_be_info'  => true,
			'cod_channel'   => 'BE',
		];

		if ($restrictRoomTypeId > 0) {
			$bookPayload['id_room_types'] = [ $restrictRoomTypeId ];
		}

		$payload = (array) \apply_filters(
			'bec_kross_calendar_book_payload',
			$bookPayload,
			$searchContext,
			$remoteUnitIdForFilter
		);

		$response = $this->api->request('GET', '/v5/calendar/book', $payload);

		$this->assertHttpOk($response);

		$decoded = KrossResponseParser::decodeBody($response->getBody());
		if (! KrossResponseParser::isSuccess($decoded)) {
			throw new ProviderException(
				self::formatEnvelopeFailure(
					\__('Kross calendar/book request was not successful.', 'booking-engine-connector'),
					$decoded
				),
				self::decodedErrorCategory($decoded)
			);
		}

		return $decoded;
	}

	/**
	 * Composes a booking-engine URL or POST form payload when a base is configured (option or filter).
	 *
	 * @param array<string, mixed> $searchContext
	 *
	 * @return array<string, mixed>|null
	 */
	public function buildCheckoutUrl(string $remoteUnitId, array $searchContext): ?array
	{
		$defaultBase = (string) \get_option('bec_kross_checkout_base_url', '');
		$base        = \trim((string) \apply_filters('bec_kross_checkout_base_url', $defaultBase, $remoteUnitId, $searchContext));
		if ($base === '') {
			return null;
		}

		$checkin  = (string) ($searchContext['checkin'] ?? $searchContext['date_from'] ?? '');
		$checkout = (string) ($searchContext['checkout'] ?? $searchContext['date_to'] ?? '');
		$adults   = (int) ($searchContext['adults'] ?? 0);
		$children = (int) ($searchContext['children'] ?? 0);
		$guests   = $adults + $children;
		if ($guests < 1) {
			$guests = 1;
		}

		$hotelId = (string) \get_option(KrossAuthenticator::OPTION_HOTEL_ID, '');
		$rateId  = (string) ($searchContext['rate_id'] ?? '');

		$defaultMethod = (string) \get_option(FallbackSettings::OPTION_CHECKOUT_HTTP_METHOD, 'get');
		$method        = \strtolower(
			(string) \apply_filters('bec_kross_checkout_http_method', $defaultMethod, $remoteUnitId, $searchContext)
		);
		if ($method !== 'post') {
			$method = 'get';
		}

		// Kross booking engine expects `id_rate` on POST; resolve via quote / bec_rate_id upstream.
		if ($method === 'post' && $rateId === '') {
			return null;
		}

		$args = [
			'hotel_id'     => $hotelId,
			'id_room_type' => $remoteUnitId,
			'from'    => $checkin,
			'to'      => $checkout,
			'guests'       => $guests,
			'adults'       => $adults,
			'children'     => $children,
			'uid'          => \bin2hex(\random_bytes(16)),
		];
		if ($rateId !== '') {
			$args['id_rate'] = $rateId;
		}

		$args['lang'] = self::resolveCheckoutLangCode($searchContext);

		$args['guests_rooms'] = self::buildGuestsRoomsJson($searchContext);

		$args = \array_filter(
			$args,
			static function ($v): bool {
				return $v !== '' && $v !== null;
			}
		);

		if ($method === 'post') {
			$out = [
				'url'          => $base,
				'method'       => 'post',
				'post_fields'  => $args,
				'label'        => \__('Book now', 'booking-engine-connector'),
			];
		} else {
			$out = [
				'url'   => \add_query_arg($args, $base),
				'label' => \__('Book now', 'booking-engine-connector'),
			];
		}

		/** @var mixed $filtered */
		$filtered = \apply_filters('bec_kross_checkout_url_result', $out, $remoteUnitId, $searchContext);
		if ($filtered === null) {
			return null;
		}
		if (! \is_array($filtered) || ! isset($filtered['url']) || (string) $filtered['url'] === '') {
			return null;
		}

		return $filtered;
	}

	/**
	 * Two-letter ISO 639-1 code for the Kross booking engine (`lang` query/post field).
	 *
	 * Uses optional `lang` on `$searchContext` (e.g. from `bec_quote_search_context`), otherwise
	 * the active WordPress locale (`determine_locale()` / `get_locale()`).
	 *
	 * @param array<string, mixed> $searchContext
	 */
	private static function resolveCheckoutLangCode(array $searchContext): string
	{
		$explicit = isset($searchContext['lang']) ? \trim((string) $searchContext['lang']) : '';
		if ($explicit !== '') {
			$explicit = \strtolower($explicit);
			if (\preg_match('/^[a-z]{2}$/', $explicit)) {
				return $explicit;
			}
		}

		$locale = Multilingual::filteredSiteLocale('kross_checkout');
		$locale = \str_replace('-', '_', $locale);
		$primary = \explode('_', $locale, 2)[0];
		$code = \strtolower(\substr($primary, 0, 2));
		if ($code === '' || ! \preg_match('/^[a-z]{2}$/', $code)) {
			return 'en';
		}

		return $code;
	}

	/**
	 * Kross booking engine expects `guests_rooms` as a JSON array string (one room object with stringified adults/children counts).
	 *
	 * @param array<string, mixed> $searchContext
	 */
	private static function buildGuestsRoomsJson(array $searchContext): string
	{
		$adults   = (string) (int) ($searchContext['adults'] ?? 0);
		$children = (int) ($searchContext['children'] ?? 0);
		$agesRaw  = $searchContext['children_ages'] ?? [];
		$ages     = \is_array($agesRaw) ? \array_map('intval', $agesRaw) : [];
		while (\count($ages) < $children) {
			$ages[] = 0;
		}
		$ages = \array_slice($ages, 0, $children);

		$room = [
			'adults'         => $adults,
			'children'       => (string) $children,
			'infant'         => 0,
			'children_age'   => $ages,
		];

		$payload = [ $room ];

		/** @var array<int, array<string, mixed>> $filtered */
		$filtered = \apply_filters('bec_kross_guests_rooms_payload', $payload, $searchContext);

		return (string) \wp_json_encode($filtered, \JSON_UNESCAPED_UNICODE);
	}

	private function assertHttpOk(HttpResponse $response): void
	{
		$code = $response->getStatusCode();
		if ($code < 400) {
			return;
		}

		$decoded = KrossResponseParser::decodeBody($response->getBody());
		$detail  = KrossResponseParser::getApiErrorMessage($decoded);
		$base    = \sprintf(
			/* translators: %d HTTP status */
			\__('Kross API request failed (%d).', 'booking-engine-connector'),
			$code
		);
		$message = $detail !== '' ? $base . ' ' . $detail : $base;

		throw new ProviderException(
			$message,
			self::statusToCategory($code, $decoded)
		);
	}

	/**
	 * @param array<string, mixed> $decoded
	 */
	private static function formatEnvelopeFailure(string $fallback, array $decoded): string
	{
		$detail = KrossResponseParser::getApiErrorMessage($decoded);
		if ($detail !== '') {
			return $detail;
		}

		$ec = KrossResponseParser::getErrorCode($decoded);
		if ($ec !== null) {
			return $fallback . ' ' . \sprintf(
				/* translators: %d Kross API error_code */
				\__('(error_code %d)', 'booking-engine-connector'),
				$ec
			);
		}

		return $fallback;
	}

	/**
	 * @param array<string, mixed> $decoded
	 */
	private static function decodedErrorCategory(array $decoded): string
	{
		$ec = KrossResponseParser::getErrorCode($decoded);

		return self::krossErrorCodeToCategory($ec);
	}

	private static function krossErrorCodeToCategory(?int $errorCode): string
	{
		if ($errorCode === null) {
			return ProviderErrorCategory::VALIDATION;
		}

		if (\in_array($errorCode, [ 2, 5, 10, 11, 12, 14, 17, 21 ], true)) {
			return ProviderErrorCategory::AUTH;
		}

		if (\in_array($errorCode, [ 13, 16 ], true)) {
			return ProviderErrorCategory::RATE_LIMIT;
		}

		return ProviderErrorCategory::UNKNOWN;
	}

	/**
	 * @param array<string, mixed> $decoded
	 */
	private static function statusToCategory(int $status, array $decoded = []): string
	{
		if ($status === 429) {
			return ProviderErrorCategory::RATE_LIMIT;
		}
		if ($status >= 500) {
			return ProviderErrorCategory::SERVER_ERROR;
		}
		if ($status === 401 || $status === 403) {
			return ProviderErrorCategory::AUTH;
		}

		$ec = KrossResponseParser::getErrorCode($decoded);
		if ($ec !== null) {
			return self::krossErrorCodeToCategory($ec);
		}

		return ProviderErrorCategory::UNKNOWN;
	}

	/**
	 * v5 {@see /v5/calendar/book} prefers {@see children_ages} over deprecated {@see guests}.
	 *
	 * @param array<string, mixed> $searchContext
	 *
	 * @return list<int>
	 */
	private static function resolveChildrenAges(array $searchContext, int $children): array
	{
		$raw = $searchContext['children_ages'] ?? null;
		if (\is_array($raw)) {
			$ages = [];
			foreach ($raw as $age) {
				$ages[] = (int) $age;
			}
			while (\count($ages) < $children) {
				$ages[] = 0;
			}

			return \array_slice($ages, 0, $children);
		}

		$ages = [];
		for ($i = 0; $i < $children; ++$i) {
			$ages[] = 0;
		}

		return $ages;
	}

	/**
	 * @param array<int|string, mixed> $data
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizeRoomTypesList(array $data): array
	{
		$list = self::isSequentialList($data) ? $data : ( $data !== [] ? [ $data ] : [] );
		$out  = [];

		foreach ($list as $row) {
			if (! \is_array($row)) {
				continue;
			}
			$id = (string) ($row['id_room_type'] ?? $row['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$out[] = [
				'external_id' => $id,
				'name'        => (string) ($row['name'] ?? $row['name_room_type'] ?? $row['des_room_type'] ?? $row['room_type_name'] ?? ''),
				'raw'         => $row,
			];
		}

		return $out;
	}

	/**
	 * @param array<int|string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function buildQuoteForRoomType(array $data, string $remoteUnitId): array
	{
		$list = self::isSequentialList($data) ? $data : ( $data !== [] ? [ $data ] : [] );
		$rows = [];

		foreach ($list as $row) {
			if (! \is_array($row)) {
				continue;
			}
			$rid = (string) ($row['id_room_type'] ?? '');
			if ($rid === $remoteUnitId) {
				$rows[] = $row;
			}
		}

		return [
			'id_room_type' => $remoteUnitId,
			'rows'         => $rows,
			'available'    => $rows !== [],
		];
	}

	/**
	 * @param array<int|string, mixed> $a
	 */
	private static function isSequentialList(array $a): bool
	{
		$expected = 0;
		foreach (\array_keys($a) as $k) {
			if ($k !== $expected) {
				return false;
			}
			++$expected;
		}

		return true;
	}
}
