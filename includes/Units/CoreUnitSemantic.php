<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units;

/**
 * Semantic identifiers for canonical unit data shared across booking providers.
 * Use with {@see CoreUnitMetaKeys} and {@see ProviderInterface::extractCoreUnitFields()}.
 */
final class CoreUnitSemantic
{
	public const NAME = 'name';

	/** Single formatted address line for display / geocoding (provider may compose from parts). */
	public const ADDRESS_FULL = 'address_full';

	public const LAT = 'lat';

	public const LNG = 'lng';

	/** Comma-separated latitude and longitude (`lat,lng`) for maps and deep links. */
	public const LAT_LNG = 'lat_lng';

	public const OCC_MIN = 'occ_min';

	public const OCC_MAX = 'occ_max';

	public const CHECK_IN_FROM = 'check_in_from';

	public const CHECK_IN_TO = 'check_in_to';

	public const CHECK_OUT_UNTIL = 'check_out_until';

	/** Number of rooms / bedrooms (integer semantics). */
	public const ROOMS = 'rooms';

	/** Bathrooms count (may be fractional, e.g. 1.5). */
	public const BATHROOMS = 'bathrooms';

	/** Primary description for the active site locale (or merged). */
	public const DESCRIPTION = 'description';

	public const SQM = 'sqm';

	/**
	 * Structured amenities list; each item is normalised by {@see AmenityItem::normalizeList()}.
	 *
	 * @see AmenityItem
	 */
	public const AMENITIES = 'amenities';

	/**
	 * Gallery: JSON array of attachment IDs (integers), or a remote payload for sync import (see {@see CoreUnitFieldRegistry}).
	 */
	public const GALLERY = 'gallery';

	/** Italian national identification code for accommodation units (Codice Identificativo Nazionale). */
	public const CIN = 'cin';

	/**
	 * @return list<string>
	 */
	public static function all(): array
	{
		return [
			self::NAME,
			self::ADDRESS_FULL,
			self::LAT,
			self::LNG,
			self::LAT_LNG,
			self::OCC_MIN,
			self::OCC_MAX,
			self::CHECK_IN_FROM,
			self::CHECK_IN_TO,
			self::CHECK_OUT_UNTIL,
			self::ROOMS,
			self::BATHROOMS,
			self::DESCRIPTION,
			self::SQM,
			self::AMENITIES,
			self::GALLERY,
			self::CIN,
		];
	}
}
