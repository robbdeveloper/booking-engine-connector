<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * Synthetic Kross v5 inventory payloads for test mode (generic fictional data).
 */
final class KrossTestData
{
	/**
	 * @return array<string, mixed>
	 */
	public static function categoriesEnvelope(): array
	{
		$data = [
			[
				'id_room_type_category'   => 9001,
				'name_room_type_category' => 'Appartamenti',
				'names'                   => [
					'it' => 'Appartamenti',
					'en' => 'Apartments',
					'es' => 'Apartamentos',
				],
			],
			[
				'id_room_type_category'   => 9002,
				'name_room_type_category' => 'Ville',
				'names'                   => [
					'it' => 'Ville',
					'en' => 'Villas',
					'es' => 'Villas',
				],
			],
			[
				'id_room_type_category'   => 9003,
				'name_room_type_category' => 'Suite',
				'names'                   => [
					'it' => 'Suite',
					'en' => 'Suite',
					'es' => 'Suite',
				],
			],
			[
				'id_room_type_category'   => 9004,
				'name_room_type_category' => 'Holiday home',
				'names'                   => [
					'en' => 'Holiday home',
				],
			],
		];

		return [
			'data'        => $data,
			'total_count' => \count($data),
			'count'       => \count($data),
			'ruid'        => 'bec-test-room-type-categories',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function roomTypesEnvelope(): array
	{
		$rows = self::roomTypes();

		return [
			'data'        => $rows,
			'total_count' => \count($rows),
			'count'       => \count($rows),
			'limit'       => \count($rows),
			'offset'      => 0,
			'page'        => 0,
			'has_next_page' => false,
			'ruid'        => 'bec-test-room-types',
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function roomTypes(): array
	{
		return [
			self::roomType(
				9101,
				'Demo Coastal Apartment',
				'DEMO-APT-01',
				9001,
				'Via del Mare 12',
				'Olbia',
				'SS',
				'07026',
				41.0892,
				9.4435,
				4,
				2,
				1,
				80,
				'250.00',
				[
					'it' => 'Appartamento Demo Costa',
					'en' => 'Demo Coastal Apartment',
				],
				[
					'it' => 'Appartamento luminoso a due passi dal mare, ideale per coppie o piccole famiglie.',
					'en' => 'Bright apartment steps from the sea, ideal for couples or small families.',
				],
				self::amenitiesWifiAcKitchen(),
				self::imagesCoastal(),
				2,
				1
			),
			self::roomType(
				9102,
				'Demo Hilltop Villa',
				'DEMO-VIL-01',
				9002,
				'Strada Panoramica 5',
				'Porto Cervo',
				'SS',
				'07021',
				41.1301,
				9.5298,
				8,
				4,
				3,
				220,
				'1200.00',
				[
					'it' => 'Villa Demo Collina',
					'en' => 'Demo Hilltop Villa',
				],
				[
					'it' => 'Villa con piscina e vista panoramica, perfetta per gruppi e famiglie numerose.',
					'en' => 'Villa with pool and panoramic views, perfect for groups and large families.',
				],
				self::amenitiesPoolWifiParking(),
				self::imagesVilla(),
				4,
				3
			),
			self::roomType(
				9103,
				'Demo City Suite',
				'DEMO-SUI-01',
				9003,
				'Corso Umberto 88',
				'Sassari',
				'SS',
				'07100',
				40.7259,
				8.5557,
				2,
				1,
				1,
				55,
				'180.00',
				[
					'it' => 'Suite Demo Centro',
					'en' => 'Demo City Suite',
				],
				[
					'it' => 'Suite elegante nel centro storico con tutti i comfort moderni.',
					'en' => 'Elegant suite in the historic centre with modern comforts.',
				],
				self::amenitiesWifiAc(),
				self::imagesSuite(),
				1,
				1
			),
			self::roomType(
				9104,
				'Demo Garden Cottage',
				'DEMO-HOL-01',
				9004,
				'Via Verde 3',
				'Alghero',
				'SS',
				'07041',
				40.5589,
				8.3193,
				6,
				3,
				2,
				120,
				'450.00',
				[
					'it' => 'Cottage Demo Giardino',
					'en' => 'Demo Garden Cottage',
				],
				[
					'it' => 'Cottage immerso nel verde con ampio giardino e area barbecue.',
					'en' => 'Cottage surrounded by greenery with a large garden and barbecue area.',
				],
				self::amenitiesGardenBbq(),
				self::imagesCottage(),
				3,
				2
			),
			self::roomType(
				9105,
				'Demo Loft Studio',
				'DEMO-STU-01',
				null,
				'Piazza della Repubblica 2',
				'Cagliari',
				'CA',
				'09124',
				39.2238,
				9.1217,
				2,
				1,
				1,
				45,
				'95.00',
				[
					'it' => 'Loft Studio Demo',
					'en' => 'Demo Loft Studio',
				],
				[
					'it' => 'Monolocale open space nel cuore della città, senza categoria assegnata.',
					'en' => 'Open-plan studio in the city centre, uncategorized listing.',
				],
				self::amenitiesWifiKitchen(),
				self::imagesStudio(),
				1,
				1
			),
			self::roomType(
				9106,
				'Demo Family Villa',
				'DEMO-VIL-02',
				9002,
				'Località Pineta 7',
				'San Teodoro',
				'NU',
				'07052',
				40.7710,
				9.6710,
				10,
				5,
				4,
				280,
				'950.00',
				[
					'it' => 'Villa Famiglia Demo',
					'en' => 'Demo Family Villa',
				],
				[
					'it' => 'Ampia villa familiare con piscina e spazi esterni per il relax.',
					'en' => 'Spacious family villa with pool and outdoor living spaces.',
				],
				self::amenitiesPoolWifiParking(),
				self::imagesFamilyVilla(),
				5,
				3
			),
		];
	}

	/**
	 * @param array<string, string> $beName
	 * @param array<string, string> $beDescription
	 * @param list<array<string, mixed>> $amenities
	 * @param list<array<string, mixed>> $images
	 *
	 * @return array<string, mixed>
	 */
	private static function roomType(
		int $idRoomType,
		string $nameRoomType,
		string $codType,
		?int $categoryId,
		string $address,
		string $city,
		string $area,
		string $postCode,
		float $latitude,
		float $longitude,
		int $maxOccupancy,
		int $bedrooms,
		int $bathrooms,
		int $sizeSqm,
		string $startingPrice,
		array $beName,
		array $beDescription,
		array $amenities,
		array $images,
		int $imageCount,
		int $mandatoryServiceCount
	): array {
		$bedroomDetails = [];
		for ($i = 0; $i < $bedrooms; ++$i) {
			$bedroomDetails[] = [
				'type'      => 'BEDROOM',
				'beds'      => [ 'queen_bed' => '1' ],
				'amenities' => [],
			];
		}

		$bathroomDetails = [];
		for ($i = 0; $i < $bathrooms; ++$i) {
			$bathroomDetails[] = [
				'type'      => 'FULL_BATH',
				'amenities' => [ 'AMENITY_SHOWER', 'AMENITY_TOILET' ],
			];
		}

		$mandatoryServices = [];
		if ($mandatoryServiceCount > 0) {
			$mandatoryServices[] = [
				'id_service'       => '9001',
				'name'             => 'DEMO CLEANING FEE',
				'price'            => '150.00',
				'price_for_night'  => null,
				'price_for_person' => null,
			];
		}

		return [
			'id_property'           => 1,
			'id_room_type'          => $idRoomType,
			'name_room_type'        => $nameRoomType,
			'cod_type'              => $codType,
			'address'               => $address,
			'city'                  => $city,
			'area'                  => $area,
			'post_code'             => $postCode,
			'cod_country'           => 'IT',
			'qt_guests'             => $maxOccupancy,
			'min_occupancy'         => 1,
			'max_occupancy'         => $maxOccupancy,
			'id_group'              => null,
			'name_group'            => null,
			'group_color'           => null,
			'id_be_group'           => null,
			'latitude'              => $latitude,
			'longitude'             => $longitude,
			'date_creation'         => '2025-01-15 10:00:00',
			'id_room_type_category' => $categoryId,
			'stop_sell'             => null,
			'size_sqm'              => $sizeSqm,
			'floor'                 => null,
			'check_in_from'         => '15:00',
			'check_in_to'           => null,
			'check_out_to'          => '10:00',
			'n_bedrooms'            => $bedrooms,
			'currency'              => 'EUR',
			'homepage'              => false,
			'starting_from_price'   => $startingPrice,
			'be_only_request'       => false,
			'hide_be'               => false,
			'be_enabled'            => [ 'demo-site' ],
			'number_of_bedrooms'    => $bedrooms,
			'number_of_bathrooms'   => $bathrooms,
			'pets_allowed'          => false,
			'baby_friendly'         => true,
			'descr_room_type'       => $beDescription['en'] ?? '',
			'amenities'             => $amenities,
			'bedroom_details'       => $bedroomDetails,
			'bathroom_details'      => $bathroomDetails,
			'images'                => \array_slice($images, 0, $imageCount),
			'custom_fields'         => [],
			'be_name'               => $beName,
			'be_description'        => $beDescription,
			'url_video'               => [],
			'meta_title'              => [],
			'meta_description'        => [],
			'meta_keywords'           => [],
			'mandatory_services'      => $mandatoryServices,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function amenity(string $cod, string $en, string $it): array
	{
		return [
			'cod_amenity'               => $cod,
			'name_amenity'                => $en,
			'name_amenity_translations'   => [
				'en' => $en,
				'it' => $it,
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function amenitiesWifiAcKitchen(): array
	{
		return [
			self::amenity('wireless_internet_connection', 'Wi-Fi', 'Internet wireless'),
			self::amenity('air_conditioning', 'Air conditioning', 'Aria condizionata'),
			self::amenity('kitchen', 'Kitchen', 'Cucina'),
			self::amenity('washer', 'Washer', 'Lavatrice'),
			self::amenity('towels', 'Towels', 'Asciugamani'),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function amenitiesPoolWifiParking(): array
	{
		return [
			self::amenity('pool', 'Swimming Pool', 'Piscina'),
			self::amenity('wireless_internet_connection', 'Wi-Fi', 'Internet wireless'),
			self::amenity('free_parking', 'Free Parking', 'Parcheggio gratuito'),
			self::amenity('air_conditioning', 'Air conditioning', 'Aria condizionata'),
			self::amenity('bbq_area', 'BBQ Area', 'Area barbecue'),
			self::amenity('garden', 'Garden', 'Giardino'),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function amenitiesWifiAc(): array
	{
		return [
			self::amenity('wireless_internet_connection', 'Wi-Fi', 'Internet wireless'),
			self::amenity('air_conditioning', 'Air conditioning', 'Aria condizionata'),
			self::amenity('hairdryer', 'Hairdryer', 'Phon'),
			self::amenity('linens', 'Bed Linen', 'Biancheria da letto'),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function amenitiesGardenBbq(): array
	{
		return [
			self::amenity('garden', 'Garden', 'Giardino'),
			self::amenity('bbq_area', 'BBQ Area', 'Area barbecue'),
			self::amenity('wireless_internet_connection', 'Wi-Fi', 'Internet wireless'),
			self::amenity('kitchen', 'Kitchen', 'Cucina'),
			self::amenity('free_parking', 'Free Parking', 'Parcheggio gratuito'),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function amenitiesWifiKitchen(): array
	{
		return [
			self::amenity('wireless_internet_connection', 'Wi-Fi', 'Internet wireless'),
			self::amenity('kitchen', 'Kitchen', 'Cucina'),
			self::amenity('stove', 'Kitchen Stove', 'Fornelli'),
			self::amenity('refrigerator', 'Refrigerator', 'Frigorifero'),
		];
	}

	/**
	 * @param list<string> $urls
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function buildImages(array $urls): array
	{
		$out = [];
		foreach ($urls as $i => $url) {
			$out[] = [
				'url'         => $url,
				'main'        => $i === 0,
				'image_order' => $i + 1,
			];
		}

		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function imagesCoastal(): array
	{
		return self::buildImages([
			'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=1200',
			'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=1200',
			'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=1200',
			'https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=1200',
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function imagesVilla(): array
	{
		return self::buildImages([
			'https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=1200',
			'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200',
			'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200',
			'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=1200',
			'https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde?w=1200',
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function imagesSuite(): array
	{
		return self::buildImages([
			'https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?w=1200',
			'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=1200',
			'https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?w=1200',
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function imagesCottage(): array
	{
		return self::buildImages([
			'https://images.unsplash.com/photo-1518780664697-55e3ad9be7d4?w=1200',
			'https://images.unsplash.com/photo-1605276374101-dee6cdf6d1b7?w=1200',
			'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1200',
			'https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?w=1200',
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function imagesStudio(): array
	{
		return self::buildImages([
			'https://images.unsplash.com/photo-1536376072261-38c75010e6c9?w=1200',
			'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=1200',
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function imagesFamilyVilla(): array
	{
		return self::buildImages([
			'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=1200',
			'https://images.unsplash.com/photo-1600585154526-990dced4db0d?w=1200',
			'https://images.unsplash.com/photo-1600607687644-c7171b42498f?w=1200',
			'https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?w=1200',
		]);
	}
}
