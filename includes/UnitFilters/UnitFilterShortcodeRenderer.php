<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Taxonomies\UnitAmenityTaxonomy;

/**
 * Renders `[bec_unit_filters]` with GET form, preserved search params, and BEM markup.
 */
final class UnitFilterShortcodeRenderer
{
	/**
	 * @param array<string, string> $atts
	 */
	public static function render(array $atts): string
	{
		$a = \shortcode_atts(
			[
				'filters'         => \implode(',', UnitFilterRegistry::defaultShortcodeFilterIds()),
				'layout'          => 'inline',
				'show_reset'      => '1',
				'amenities'       => 'selected',
				'amenities_limit' => '0',
				'action'          => '',
			],
			$atts,
			'bec_unit_filters'
		);

		$filterIds = self::parseFilterIds((string) $a['filters']);
		if ($filterIds === []) {
			return '';
		}

		$fieldDefs = UnitFilterRegistry::resolveForShortcode($filterIds);
		if ($fieldDefs === []) {
			return '';
		}

		$request = UnitFilterRequest::fromRequest();
		$layout  = \sanitize_key(\strtolower(\trim((string) $a['layout'])));
		if ($layout !== 'stacked') {
			$layout = 'inline';
		}

		$showReset = self::isTruthy((string) $a['show_reset']);
		$action    = self::resolveFormAction((string) $a['action']);
		$amenityLimit = \max(0, (int) $a['amenities_limit']);

		\ob_start();

		$classes = 'bec-unit-filters bec-unit-filters--' . $layout;
		echo '<form class="' . \esc_attr($classes) . '" method="get" action="' . \esc_url($action) . '">';

		self::renderPreservedHiddenFields($request);

		foreach ($fieldDefs as $def) {
			$id = $def['id'] ?? '';
			if ($id === UnitFilterRegistry::FILTER_ORDER) {
				self::renderOrderField($request);
			} elseif ($id === UnitFilterRegistry::FILTER_ROOMS) {
				self::renderRoomsField($request);
			} elseif ($id === UnitFilterRegistry::FILTER_BATHROOMS) {
				self::renderBathroomsField($request);
			} elseif ($id === UnitFilterRegistry::FILTER_AMENITIES) {
				$choices = self::resolveAmenityChoices((string) $a['amenities'], $amenityLimit);
				if ($choices === []) {
					continue;
				}
				self::renderAmenitiesField($request, $choices);
			}
		}

		echo '<div class="bec-unit-filters__actions">';
		echo '<button type="submit" class="bec-unit-filters__submit">' . \esc_html__(
			'Apply filters',
			'booking-engine-connector'
		) . '</button>';
		if ($showReset) {
			echo ' <a class="bec-unit-filters__reset" href="' . \esc_url(self::resetUrl($action)) . '">' . \esc_html__(
				'Reset filters',
				'booking-engine-connector'
			) . '</a>';
		}
		echo '</div>';

		echo '</form>';

		return (string) \ob_get_clean();
	}

	/**
	 * @return list<string>
	 */
	private static function parseFilterIds(string $raw): array
	{
		$parts = \array_map('trim', \explode(',', $raw));
		$ids   = [];
		foreach ($parts as $part) {
			$id = \sanitize_key($part);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	private static function isTruthy(string $value): bool
	{
		$v = \strtolower(\trim($value));

		return $v === '1' || $v === 'yes' || $v === 'true' || $v === 'on';
	}

	private static function resolveFormAction(string $redirectUrl): string
	{
		$raw = \trim($redirectUrl);
		if ($raw !== '') {
			$clean = \esc_url($raw);
			if ($clean !== '') {
				return $clean;
			}
		}

		if (\is_post_type_archive(\BookingEngineConnector\PostTypes\UnitPostType::getSlug())) {
			$archive = \get_post_type_archive_link(\BookingEngineConnector\PostTypes\UnitPostType::getSlug());

			return $archive !== false ? (string) $archive : \home_url('/');
		}

		global $wp;
		if (isset($wp->request)) {
			return \home_url(\add_query_arg([], $wp->request));
		}

		return \home_url('/');
	}

	private static function resetUrl(string $action): string
	{
		$ctx = SearchContext::fromRequest();
		$args = $ctx->toQueryArgs();

		if ($action === '') {
			$action = self::resolveFormAction('');
		}

		if ($args === []) {
			return $action;
		}

		return (string) \add_query_arg($args, $action);
	}

	private static function renderPreservedHiddenFields(UnitFilterRequest $request): void
	{
		$preserve = SearchContext::fromRequest()->toQueryArgs();
		$filterParams = \array_fill_keys(UnitFilterRequest::filterParamNames(), true);

		/** @var list<string> $extra */
		$extra = (array) \apply_filters('bec_unit_filters_preserve_query_keys', []);
		foreach ($extra as $key) {
			$key = \sanitize_key((string) $key);
			if ($key !== '' && ! isset($filterParams[ $key ]) && isset($_GET[ $key ])) {
				$raw = $_GET[ $key ];
				if (\is_scalar($raw)) {
					echo '<input type="hidden" name="' . \esc_attr($key) . '" value="' . \esc_attr(\wp_unslash((string) $raw)) . '" />';
				}
			}
		}

		foreach ($preserve as $key => $value) {
			if (isset($filterParams[ $key ])) {
				continue;
			}
			if (\is_array($value)) {
				foreach ($value as $v) {
					echo '<input type="hidden" name="' . \esc_attr($key) . '[]" value="' . \esc_attr((string) $v) . '" />';
				}
			} else {
				echo '<input type="hidden" name="' . \esc_attr($key) . '" value="' . \esc_attr((string) $value) . '" />';
			}
		}

		unset($request);
	}

	private static function renderOrderField(UnitFilterRequest $request): void
	{
		$current = $request->getOrder();
		echo '<div class="bec-unit-filters__field bec-unit-filters__field--order">';
		echo '<label class="bec-unit-filters__label" for="bec_filter_order">' . \esc_html__(
			'Order',
			'booking-engine-connector'
		) . '</label>';
		echo '<select class="bec-unit-filters__control" name="' . \esc_attr(UnitFilterRequest::PARAM_ORDER) . '" id="bec_filter_order">';
		echo '<option value="">' . \esc_html__('Any', 'booking-engine-connector') . '</option>';
		echo '<option value="ASC" ' . \selected($current, 'ASC', false) . '>' . \esc_html__(
			'Ascending',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="DESC" ' . \selected($current, 'DESC', false) . '>' . \esc_html__(
			'Descending',
			'booking-engine-connector'
		) . '</option>';
		echo '</select></div>';
	}

	private static function renderRoomsField(UnitFilterRequest $request): void
	{
		$min = $request->getRoomsMin();
		echo '<div class="bec-unit-filters__field bec-unit-filters__field--rooms">';
		echo '<label class="bec-unit-filters__label" for="bec_filter_rooms_min">' . \esc_html__(
			'Rooms',
			'booking-engine-connector'
		) . '</label>';
		echo '<select class="bec-unit-filters__control" name="' . \esc_attr(UnitFilterRequest::PARAM_ROOMS_MIN) . '" id="bec_filter_rooms_min">';
		echo '<option value="">' . \esc_html__('Any', 'booking-engine-connector') . '</option>';
		for ($i = 1; $i <= 10; $i++) {
			echo '<option value="' . \esc_attr((string) $i) . '" ' . \selected($min, $i, false) . '>' . \esc_html((string) $i) . '+</option>';
		}
		echo '</select></div>';
	}

	private static function renderBathroomsField(UnitFilterRequest $request): void
	{
		$min = $request->getBathroomsMin();
		$minStr = $min > 0.0 ? UnitFilterRequest::formatBathroomsMin($min) : '';
		echo '<div class="bec-unit-filters__field bec-unit-filters__field--bathrooms">';
		echo '<label class="bec-unit-filters__label" for="bec_filter_bathrooms_min">' . \esc_html__(
			'Bathrooms',
			'booking-engine-connector'
		) . '</label>';
		echo '<select class="bec-unit-filters__control" name="' . \esc_attr(UnitFilterRequest::PARAM_BATHROOMS_MIN) . '" id="bec_filter_bathrooms_min">';
		echo '<option value="">' . \esc_html__('Any', 'booking-engine-connector') . '</option>';
		foreach (['1', '1.5', '2', '2.5', '3', '4'] as $opt) {
			$selected = $minStr === $opt || ($minStr !== '' && (float) $minStr === (float) $opt);
			echo '<option value="' . \esc_attr($opt) . '" ' . \selected($selected, true, false) . '>' . \esc_html($opt) . '+</option>';
		}
		echo '</select></div>';
	}

	/**
	 * @param list<array{key: string, label: string}> $choices
	 */
	private static function renderAmenitiesField(UnitFilterRequest $request, array $choices): void
	{
		$selected = \array_fill_keys($request->getAmenityKeys(), true);
		echo '<fieldset class="bec-unit-filters__field bec-unit-filters__field--amenities">';
		echo '<legend class="bec-unit-filters__label">' . \esc_html__(
			'Amenities',
			'booking-engine-connector'
		) . '</legend>';
		echo '<div class="bec-unit-filters__checkboxes">';
		foreach ($choices as $choice) {
			$key = $choice['key'];
			$checked = isset($selected[ $key ]);
			echo '<label class="bec-unit-filters__checkbox">';
			echo '<input type="checkbox" name="' . \esc_attr(UnitFilterRequest::PARAM_AMENITIES) . '[]" value="' . \esc_attr($key) . '" ' . \checked($checked, true, false) . ' /> ';
			echo '<span class="bec-unit-filters__checkbox-text">' . \esc_html($choice['label']) . '</span>';
			echo '</label>';
		}
		echo '</div></fieldset>';
	}

	/**
	 * @return list<array{key: string, label: string}>
	 */
	private static function resolveAmenityChoices(string $amenitiesAttr, int $limit): array
	{
		$attr = \strtolower(\trim($amenitiesAttr));
		$settings = UnitFilterSettings::getAmenityOptions();

		$keys = [];
		if ($attr === '' || $attr === 'selected') {
			$keys = $settings['enabled'];
			if ($settings['order'] !== []) {
				$ordered = [];
				foreach ($settings['order'] as $key) {
					if (\in_array($key, $keys, true)) {
						$ordered[] = $key;
					}
				}
				foreach ($keys as $key) {
					if (! \in_array($key, $ordered, true)) {
						$ordered[] = $key;
					}
				}
				$keys = $ordered;
			}
		} else {
			foreach (\array_map('trim', \explode(',', $amenitiesAttr)) as $part) {
				$key = \sanitize_key($part);
				if ($key !== '') {
					$keys[] = $key;
				}
			}
		}

		if ($keys === []) {
			return [];
		}

		if ($limit > 0 && \count($keys) > $limit) {
			$keys = \array_slice($keys, 0, $limit);
		}

		$out = [];
		foreach ($keys as $key) {
			$label = $settings['labels'][ $key ] ?? '';
			if ($label === '') {
				$label = self::labelForAmenityKey($key);
			}
			$out[] = [
				'key'   => $key,
				'label' => $label !== '' ? $label : $key,
			];
		}

		return $out;
	}

	private static function labelForAmenityKey(string $key): string
	{
		if (UnitAmenityIndexer::isIndexComplete()) {
			$term = \get_term_by('slug', $key, UnitAmenityTaxonomy::TAXONOMY);
			if ($term instanceof \WP_Term) {
				return UnitAmenityTaxonomy::resolveLocalizedLabelForTerm($term);
			}
		}

		return $key;
	}
}
