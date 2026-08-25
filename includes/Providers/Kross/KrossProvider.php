<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Api\HttpClient;
use BookingEngineConnector\Api\HttpResponse;
use BookingEngineConnector\Fallback\FallbackSettings;
use BookingEngineConnector\Integrations\Multilingual;
use BookingEngineConnector\Providers\Contracts\BulkQuoteProviderInterface;
use BookingEngineConnector\Providers\Contracts\CalendarAvailabilityProviderInterface;
use BookingEngineConnector\Providers\Contracts\ProviderErrorCategory;
use BookingEngineConnector\Providers\Contracts\ProviderException;
use BookingEngineConnector\Providers\Contracts\ProviderInterface;
use BookingEngineConnector\Providers\Contracts\SearchGuestFieldMode;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Kross API v5: room types + calendar availability (see docs/KROSS-API.md).
 */
final class KrossProvider implements ProviderInterface, BulkQuoteProviderInterface, CalendarAvailabilityProviderInterface
{
	private KrossAuthenticator $authenticator;

	private KrossApiClient $api;

	/** @var array<string, array<string, mixed>> */
	private array $lastCategoryDescriptorMap = [];

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
		if (KrossTestMode::isEnabled()) {
			return true;
		}

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
	 * @param array<string, mixed> $syncPayload
	 * @param array<string, string> $atts
	 * @param array<string, mixed> $context
	 */
	public function getUnitFieldValue(array $syncPayload, string $field, array $atts, array $context): string|int|float|null
	{
		return KrossUnitFieldResolver::resolve($syncPayload, $field, $atts, $context);
	}

	/**
	 * @param array<string, mixed> $syncPayload
	 * @param array<string, string> $atts
	 * @param array<string, mixed> $context
	 *
	 * @return array{key: string, label: string, labels: array<string, string>, category?: string, icon?: string}|null
	 */
	public function getUnitAmenityItem(array $syncPayload, int $postId, string $amenityKey, array $atts, array $context): ?array
	{
		return KrossUnitAmenityResolver::resolve($syncPayload, $postId, $amenityKey, $atts, $context);
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
	 * Calls `/v5/rooms/get-room-types` and replaces the cached catalog with discovered `be_enabled` slugs.
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
		$categoryMap = [];
		if (UnitCategoryTaxonomy::isEnabled()) {
			$categoryMap = $this->fetchRoomTypeCategoriesMap();
		}

		$this->lastCategoryDescriptorMap = $categoryMap;

		if (KrossTestMode::isEnabled()) {
			$decoded = KrossTestData::roomTypesEnvelope();
		} else {
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
		}

		$data = KrossResponseParser::getDataPayload($decoded);
		$rows = $this->normalizeRoomTypesList($data, $categoryMap);

		return [ $decoded, $rows ];
	}

	/**
	 * Category descriptors from the most recent {@see fetchRemoteUnits()} call.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function getCategoryDescriptorMap(): array
	{
		return $this->lastCategoryDescriptorMap;
	}

	/**
	 * Room-type categories keyed by {@see id_room_type_category}.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws ProviderException
	 */
	private function fetchRoomTypeCategoriesMap(): array
	{
		if (KrossTestMode::isEnabled()) {
			$decoded = KrossTestData::categoriesEnvelope();
		} else {
			$payload = (array) \apply_filters('bec_kross_room_type_categories_payload', []);

			$response = $this->api->request('GET', '/v5/rooms/get-room-types-categories', $payload);

			$this->assertHttpOk($response);

			$decoded = KrossResponseParser::decodeBody($response->getBody());

			if (! KrossResponseParser::isSuccess($decoded)) {
				throw new ProviderException(
					self::formatEnvelopeFailure(
						\__('Kross get-room-types-categories request was not successful.', 'booking-engine-connector'),
						$decoded
					),
					self::decodedErrorCategory($decoded)
				);
			}
		}

		$data = KrossResponseParser::getDataPayload($decoded);
		$list = self::isSequentialList($data) ? $data : ( $data !== [] ? [ $data ] : [] );

		/** @var array<int, mixed> $list */
		$list = \apply_filters('bec_kross_room_type_categories', $list, $decoded);

		$map = [];

		foreach ($list as $item) {
			if (! \is_array($item)) {
				continue;
			}

			$normalized = $this->normalizeRoomTypeCategoryRow($item);
			$externalId = (string) ($normalized['external_id'] ?? '');
			if ($externalId === '') {
				continue;
			}

			$map[ $externalId ] = $normalized;
		}

		return $map;
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private function normalizeRoomTypeCategoryRow(array $row): array
	{
		$externalId = (string) ($row['id_room_type_category'] ?? '');
		$name       = (string) ($row['name_room_type_category'] ?? '');
		$namesRaw   = $row['names'] ?? [];
		$names      = [];

		if (\is_array($namesRaw)) {
			/** @var array<mixed, mixed> $namesRaw */
			$names = UnitCategoryTaxonomy::coerceDescriptorNamesToMap($namesRaw);
		}

		return [
			'external_id' => $externalId,
			'name'        => $name,
			'names'       => $names,
			'raw'         => $row,
		];
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

	public function getBulkAvailabilityCacheKey(): string
	{
		$hotelId  = (string) \get_option(KrossAuthenticator::OPTION_HOTEL_ID, '');
		$horizon  = self::calendarAvailabilityHorizon();
		$payload  = [
			'provider' => 'kross',
			'hotel'    => $hotelId,
			'from'     => $horizon['date_from'],
			'to'       => $horizon['date_to'],
		];

		return 'bec_kross_availability_bulk_' . \md5((string) \wp_json_encode($payload));
	}

	public function fetchBulkAvailability(): mixed
	{
		$horizon = self::calendarAvailabilityHorizon();

		$payload = [
			'date_from' => $horizon['date_from'],
			'date_to'   => $horizon['date_to'],
		];

		/**
		 * @var array<string, mixed> $payload
		 */
		$payload = (array) \apply_filters('bec_kross_get_availability_payload', $payload);

		$response = $this->api->request('GET', '/v5/calendar/get-availability', $payload);

		$this->assertHttpOk($response);

		$decoded = KrossResponseParser::decodeBody($response->getBody());
		if (! KrossResponseParser::isSuccess($decoded)) {
			throw new ProviderException(
				self::formatEnvelopeFailure(
					\__('Kross calendar/get-availability request was not successful.', 'booking-engine-connector'),
					$decoded
				),
				self::decodedErrorCategory($decoded)
			);
		}

		/**
		 * @var mixed $decoded
		 */
		return \apply_filters('bec_kross_availability_bulk', $decoded);
	}

	public function normalizeBulkAvailability(mixed $bulk): array
	{
		if (! \is_array($bulk)) {
			return [];
		}

		$data = KrossResponseParser::getDataPayload($bulk);
		if (! \is_array($data)) {
			return [];
		}

		$segments = [];
		foreach ($data as $row) {
			if (! \is_array($row)) {
				continue;
			}

			$id = (string) ($row['id_room_type'] ?? '');
			if ($id === '') {
				continue;
			}

			$dateFrom = (string) ($row['date_from'] ?? $row['date_form'] ?? '');
			$dateTo   = (string) ($row['date_to'] ?? '');
			if ($dateFrom === '' || $dateTo === '') {
				continue;
			}

			$available = (int) ($row['available'] ?? 0) > 0;

			$minimumStay = null;
			if (isset($row['minimum_stay']) && $row['minimum_stay'] !== null && $row['minimum_stay'] !== '') {
				$minimumStay = (int) $row['minimum_stay'];
				if ($minimumStay < 1) {
					$minimumStay = null;
				}
			}

			$segment = [
				'remote_unit_id' => $id,
				'date_from'      => $dateFrom,
				'date_to'        => $dateTo,
				'available'      => $available,
			];
			if ($minimumStay !== null) {
				$segment['minimum_stay'] = $minimumStay;
			}

			$segments[] = $segment;
		}

		return $segments;
	}

	public function getGetAvaCacheKey(string $beId, string $roomTypeId): string
	{
		return 'bec_kross_get_ava_' . \md5($beId . '|' . $roomTypeId);
	}

	/**
	 * @throws ProviderException
	 */
	public function fetchWidgetGetAva(string $beId, string $roomTypeId): mixed
	{
		if (KrossTestMode::isEnabled()) {
			return [];
		}

		$baseUrl = (string) \apply_filters(
			'bec_kross_get_ava_base_url',
			'https://endpoint.krossbooking.com/widget/get-ava/'
		);

		$query = [
			'be_id' => $beId,
			'idrt'  => $roomTypeId,
		];

		/**
		 * @var array<string, scalar|null> $query
		 */
		$query = (array) \apply_filters('bec_kross_get_ava_query', $query, $beId, $roomTypeId);

		$url = \add_query_arg($query, $baseUrl);

		$http     = new HttpClient();
		$response = $http->request(
			'GET',
			$url,
			[
				'bec_skip_auth'     => true,
				'bec_log_profile'   => 'business',
				'bec_provider_slug' => 'kross',
				'bec_log_message'   => 'Kross widget get-ava',
			]
		);

		$this->assertHttpOk($response);

		$decoded = KrossResponseParser::decodeBody($response->getBody());

		/**
		 * @var mixed $decoded
		 */
		return \apply_filters('bec_kross_get_ava_payload', $decoded, $beId, $roomTypeId);
	}

	/**
	 * Map widget get-ava JSON to daterangepicker hint structures.
	 *
	 * @return array{
	 *     unavailable_ranges: list<array{from: string, to: string}>,
	 *     invalid_checkin_ranges: list<array{from: string, to: string}>,
	 *     invalid_checkout_ranges: list<array{from: string, to: string}>,
	 *     checkout_only_ranges: list<array{from: string, to: string}>,
	 *     stay_rules: array<string, array{mi: int, ma?: int}>
	 * }
	 */
	public static function normalizeGetAvaAvailability(
		mixed $payload,
		string $dateFrom,
		string $dateTo,
		int $globalMinNights = 1
	): array {
		$availability = [];
		if (\is_array($payload) && isset($payload['availability']) && \is_array($payload['availability'])) {
			$availability = $payload['availability'];
		}

		$globalMinNights = \max(1, $globalMinNights);
		$pluginMaxNights = (int) \apply_filters('bec_search_max_nights', 365, null);
		if ($pluginMaxNights < 1) {
			$pluginMaxNights = 365;
		}

		$presentDays      = [];
		$invalidCheckout  = [];
		$stayRules        = [];
		$closedCheckin    = [];

		foreach ($availability as $day => $row) {
			if (! \is_string($day) || ! \is_array($row)) {
				continue;
			}
			if ($day < $dateFrom || $day > $dateTo) {
				continue;
			}

			$presentDays[$day] = true;

			$mi = isset($row['mi']) ? (int) $row['mi'] : 0;
			$ma = isset($row['ma']) ? (int) $row['ma'] : 0;
			if (self::widgetFlagIsSet($row['ca'] ?? null)) {
				$closedCheckin[$day] = true;
			}
			if (self::widgetFlagIsSet($row['cd'] ?? null)) {
				$invalidCheckout[$day] = true;
			}

			$rule = [];
			if ($mi > 0) {
				$rule['mi'] = $mi;
			}
			if ($ma > 0) {
				$rule['ma'] = $ma;
			}
			if ($rule !== []) {
				$stayRules[$day] = $rule;
			}
		}

		$checkoutOnly = self::deriveGetAvaCheckoutOnlyDays($presentDays, $dateFrom, $dateTo);
		$invalidCheckin = [];
		$presentRuns    = self::contiguousPresentRuns($presentDays, $dateFrom, $dateTo);

		foreach ($presentRuns as $run) {
			$runLength = \count($run);
			foreach ($run as $day) {
				if (isset($closedCheckin[$day])) {
					$invalidCheckin[$day] = true;
					continue;
				}

				$isValidCheckin = self::getAvaDayHasLegalCheckout(
					$day,
					$presentDays,
					$checkoutOnly,
					$invalidCheckout,
					$stayRules,
					$globalMinNights,
					$pluginMaxNights,
					$dateTo
				);

				if ($isValidCheckin) {
					continue;
				}

				$invalidCheckin[$day] = true;

				if ($runLength > 1 && $day !== $run[0] && ! isset($checkoutOnly[$day])) {
					$checkoutOnly[$day] = true;
				}
			}
		}

		$unavailableDays = [];
		foreach (self::iterateCalendarDays($dateFrom, $dateTo) as $day) {
			if (isset($presentDays[$day]) || isset($checkoutOnly[$day])) {
				continue;
			}
			$unavailableDays[$day] = true;
		}

		return [
			'unavailable_ranges'      => self::markedDaysToRanges($unavailableDays, $dateFrom, $dateTo),
			'invalid_checkin_ranges'  => self::markedDaysToRanges($invalidCheckin, $dateFrom, $dateTo),
			'invalid_checkout_ranges' => self::markedDaysToRanges($invalidCheckout, $dateFrom, $dateTo),
			'checkout_only_ranges'    => self::markedDaysToRanges($checkoutOnly, $dateFrom, $dateTo),
			'stay_rules'              => $stayRules,
		];
	}

	/**
	 * Bridge departure days immediately after each inventory run (not present in JSON).
	 *
	 * @param array<string, true> $presentDays
	 *
	 * @return array<string, true>
	 */
	private static function deriveGetAvaCheckoutOnlyDays(
		array $presentDays,
		string $dateFrom,
		string $dateTo
	): array {
		$checkoutOnly = [];

		foreach (self::iterateCalendarDays($dateFrom, $dateTo) as $day) {
			if (isset($presentDays[$day])) {
				continue;
			}

			$prevTs = \strtotime($day . ' 00:00:00 UTC');
			if ($prevTs === false) {
				continue;
			}
			$prevDay = \gmdate('Y-m-d', $prevTs - \DAY_IN_SECONDS);
			if (isset($presentDays[$prevDay])) {
				$checkoutOnly[$day] = true;
			}
		}

		return $checkoutOnly;
	}

	/**
	 * @param array<string, true> $presentDays
	 *
	 * @return list<list<string>>
	 */
	private static function contiguousPresentRuns(
		array $presentDays,
		string $dateFrom,
		string $dateTo
	): array {
		$runs     = [];
		$current  = [];

		foreach (self::iterateCalendarDays($dateFrom, $dateTo) as $day) {
			if (isset($presentDays[$day])) {
				$current[] = $day;
				continue;
			}

			if ($current !== []) {
				$runs[]  = $current;
				$current = [];
			}
		}

		if ($current !== []) {
			$runs[] = $current;
		}

		return $runs;
	}

	/**
	 * @param array<string, true>                              $presentDays
	 * @param array<string, true>                              $checkoutOnlyDays
	 * @param array<string, true>                              $invalidCheckoutDays
	 * @param array<string, array{mi?: int, ma?: int}>           $stayRules
	 */
	private static function getAvaDayHasLegalCheckout(
		string $checkinDay,
		array $presentDays,
		array $checkoutOnlyDays,
		array $invalidCheckoutDays,
		array $stayRules,
		int $globalMinNights,
		int $pluginMaxNights,
		string $dateTo
	): bool {
		$rule         = $stayRules[$checkinDay] ?? [];
		$effectiveMin = $globalMinNights;
		if (isset($rule['mi']) && (int) $rule['mi'] > $effectiveMin) {
			$effectiveMin = (int) $rule['mi'];
		}

		$effectiveMax = $pluginMaxNights;
		if (isset($rule['ma']) && (int) $rule['ma'] > 0) {
			$effectiveMax = \min($pluginMaxNights, (int) $rule['ma']);
		}

		$checkinTs = \strtotime($checkinDay . ' 00:00:00 UTC');
		$endTs     = \strtotime($dateTo . ' 00:00:00 UTC');
		if ($checkinTs === false || $endTs === false) {
			return false;
		}

		for ($nights = $effectiveMin; $nights <= $effectiveMax; $nights++) {
			$checkoutTs = $checkinTs + ($nights * \DAY_IN_SECONDS);
			if ($checkoutTs > $endTs) {
				break;
			}

			$checkoutDay = \gmdate('Y-m-d', $checkoutTs);
			if (isset($invalidCheckoutDays[$checkoutDay])) {
				continue;
			}

			if (! self::getAvaOccupiedNightsArePresent($checkinDay, $checkoutDay, $presentDays)) {
				continue;
			}

			if (
				isset($presentDays[$checkoutDay])
				|| isset($checkoutOnlyDays[$checkoutDay])
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Occupied nights are [check-in, check-out) — checkout day is not an occupied night.
	 *
	 * @param array<string, true> $presentDays
	 */
	private static function getAvaOccupiedNightsArePresent(
		string $checkinDay,
		string $checkoutDay,
		array $presentDays
	): bool {
		$startTs = \strtotime($checkinDay . ' 00:00:00 UTC');
		$endTs   = \strtotime($checkoutDay . ' 00:00:00 UTC');
		if ($startTs === false || $endTs === false || $endTs <= $startTs) {
			return false;
		}

		for ($ts = $startTs; $ts < $endTs; $ts += \DAY_IN_SECONDS) {
			$night = \gmdate('Y-m-d', $ts);
			if (! isset($presentDays[$night])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return list<string>
	 */
	private static function iterateCalendarDays(string $dateFrom, string $dateTo): array
	{
		$startTs = \strtotime($dateFrom . ' 00:00:00 UTC');
		$endTs   = \strtotime($dateTo . ' 00:00:00 UTC');
		if ($startTs === false || $endTs === false || $startTs > $endTs) {
			return [];
		}

		$days = [];
		for ($ts = $startTs; $ts <= $endTs; $ts += \DAY_IN_SECONDS) {
			$days[] = \gmdate('Y-m-d', $ts);
		}

		return $days;
	}

	private static function widgetFlagIsSet(mixed $value): bool
	{
		if ($value === null || $value === '' || $value === false) {
			return false;
		}

		if (\is_numeric($value)) {
			return (int) $value > 0;
		}

		if (\is_string($value)) {
			return \trim($value) !== '' && \trim($value) !== '0';
		}

		return (bool) $value;
	}

	/**
	 * @param array<string, true> $markedDays
	 *
	 * @return list<array{from: string, to: string}>
	 */
	private static function markedDaysToRanges(array $markedDays, string $dateFrom, string $dateTo): array
	{
		if ($markedDays === []) {
			return [];
		}

		$ranges     = [];
		$rangeStart = null;

		foreach (self::iterateCalendarDays($dateFrom, $dateTo) as $day) {
			if (isset($markedDays[$day])) {
				if ($rangeStart === null) {
					$rangeStart = $day;
				}
				continue;
			}

			if ($rangeStart !== null) {
				$prevTs = \strtotime($day . ' 00:00:00 UTC');
				if ($prevTs !== false) {
					$ranges[] = [
						'from' => $rangeStart,
						'to'   => \gmdate('Y-m-d', $prevTs - \DAY_IN_SECONDS),
					];
				}
				$rangeStart = null;
			}
		}

		if ($rangeStart !== null) {
			$ranges[] = [
				'from' => $rangeStart,
				'to'   => $dateTo,
			];
		}

		return $ranges;
	}

	/**
	 * @return array{date_from: string, date_to: string}
	 */
	private static function calendarAvailabilityHorizon(): array
	{
		$maxDays = (int) \apply_filters('bec_daterangepicker_max_date_from_today', 730, null);
		if ($maxDays < 1) {
			$maxDays = 730;
		}

		return [
			'date_from' => \gmdate('Y-m-d'),
			'date_to'   => \gmdate('Y-m-d', \strtotime('+' . $maxDays . ' days')),
		];
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
	/**
	 * @param array<int|string, mixed> $data
	 * @param array<string, array<string, mixed>> $categoryMap
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizeRoomTypesList(array $data, array $categoryMap = []): array
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

			$item = [
				'external_id' => $id,
				'name'        => (string) ($row['name'] ?? $row['name_room_type'] ?? $row['des_room_type'] ?? $row['room_type_name'] ?? ''),
				'raw'         => $row,
			];

			$idCat = (string) ($row['id_room_type_category'] ?? '');
			$descriptor = null;

			if ($idCat !== '' && isset($categoryMap[ $idCat ])) {
				$descriptor = $categoryMap[ $idCat ];
			}

			/** @var mixed $descriptor */
			$descriptor = \apply_filters('bec_kross_room_type_category_from_row', $descriptor, $row, $categoryMap);

			if (\is_array($descriptor) && (string) ($descriptor['external_id'] ?? '') !== '') {
				$item['unit_category'] = $descriptor;
			}

			$out[] = $item;
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
