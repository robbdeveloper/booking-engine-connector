<?php

declare(strict_types=1);

namespace BookingEngineConnector\Elementor\DynamicTags;

use BookingEngineConnector\Units\UnitGalleryPresenter;
use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;

/**
 * Elementor gallery dynamic tag: canonical unit gallery (`bec_core_gallery`) with a configurable limit.
 *
 * Use on Gallery / Media Carousel widgets: dynamic icon on the gallery control → Booking Engine → Unit gallery.
 */
final class UnitGalleryTag extends Data_Tag
{
	public function get_name(): string
	{
		return 'bec-unit-gallery';
	}

	public function get_title(): string
	{
		return \esc_html__('Unit gallery', 'booking-engine-connector');
	}

	public function get_group(): string
	{
		return 'bec';
	}

	public function get_categories(): array
	{
		return [ TagsModule::GALLERY_CATEGORY ];
	}

	protected function register_controls(): void
	{
		$this->add_control(
			'limit',
			[
				'label'       => \esc_html__('Image limit', 'booking-engine-connector'),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 6,
				'min'         => 0,
				'step'        => 1,
				'description' => \esc_html__('Maximum images to include. Use 0 for the full gallery.', 'booking-engine-connector'),
			]
		);

		$this->add_control(
			'offset',
			[
				'label'   => \esc_html__('Offset', 'booking-engine-connector'),
				'type'    => Controls_Manager::NUMBER,
				'default' => 0,
				'min'     => 0,
				'step'    => 1,
			]
		);

		$this->add_control(
			'unit_id',
			[
				'label'       => \esc_html__('Unit ID', 'booking-engine-connector'),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'step'        => 1,
				'description' => \esc_html__('Leave empty to use the current post (single unit or loop item).', 'booking-engine-connector'),
			]
		);
	}

	/**
	 * @param array<string, mixed> $options
	 *
	 * @return list<array{id: int}>
	 */
	public function get_value(array $options = [])
	{
		unset($options);

		$settings = $this->get_settings();
		$limit    = isset($settings['limit']) ? (int) $settings['limit'] : 6;
		$offset   = isset($settings['offset']) ? (int) $settings['offset'] : 0;
		$unitId   = isset($settings['unit_id']) ? (int) $settings['unit_id'] : 0;

		$limit  = \max(0, $limit);
		$offset = \max(0, $offset);

		$postId = UnitGalleryPresenter::resolveUnitPostId($unitId);
		if ($postId < 1) {
			return [];
		}

		$context = [
			'source'   => 'elementor',
			'limit'    => $limit,
			'offset'   => $offset,
			'unit_id'  => $unitId,
		];

		$rows = UnitGalleryPresenter::elementorGalleryRows($postId, $limit, $offset, $context);

		return (array) \apply_filters('bec_unit_gallery_elementor_value', $rows, $postId, $settings);
	}
}
