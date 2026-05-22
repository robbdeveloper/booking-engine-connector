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
	private static int $amenitiesInstanceCount = 0;

	private static int $pickerInstanceCount = 0;

	private static bool $hideLabels = false;

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
				'hide_labels'     => '1',
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

		$showReset    = self::isTruthy((string) $a['show_reset']);
		$action       = self::resolveFormAction((string) $a['action']);
		$amenityLimit = \max(0, (int) $a['amenities_limit']);
		self::$hideLabels = self::isTruthy((string) $a['hide_labels']);

		\ob_start();

		$classes = 'bec-unit-filters bec-unit-filters--' . $layout;
		if (self::$hideLabels) {
			$classes .= ' bec-unit-filters--hide-labels';
		}
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
		self::renderSelectPickerField(
			'order',
			'bec_filter_order',
			UnitFilterRequest::PARAM_ORDER,
			\__('Order', 'booking-engine-connector'),
			$current,
			[
				[
					'value' => '',
					'label' => \__('Any', 'booking-engine-connector'),
				],
				[
					'value' => 'ASC',
					'label' => \__('Ascending', 'booking-engine-connector'),
				],
				[
					'value' => 'DESC',
					'label' => \__('Descending', 'booking-engine-connector'),
				],
			]
		);
	}

	private static function renderRoomsField(UnitFilterRequest $request): void
	{
		$min = $request->getRoomsMin();
		$current = $min > 0 ? (string) $min : '';
		$options = [
			[
				'value' => '',
				'label' => \__('Any', 'booking-engine-connector'),
			],
		];
		for ($i = 1; $i <= 10; $i++) {
			$options[] = [
				'value' => (string) $i,
				'label' => (string) $i . '+',
			];
		}
		self::renderSelectPickerField(
			'rooms',
			'bec_filter_rooms_min',
			UnitFilterRequest::PARAM_ROOMS_MIN,
			\__('Rooms', 'booking-engine-connector'),
			$current,
			$options
		);
	}

	private static function renderBathroomsField(UnitFilterRequest $request): void
	{
		$min = $request->getBathroomsMin();
		$minStr = $min > 0.0 ? UnitFilterRequest::formatBathroomsMin($min) : '';
		$options = [
			[
				'value' => '',
				'label' => \__('Any', 'booking-engine-connector'),
			],
		];
		foreach (['1', '1.5', '2', '2.5', '3', '4'] as $opt) {
			$options[] = [
				'value' => $opt,
				'label' => $opt . '+',
			];
		}
		self::renderSelectPickerField(
			'bathrooms',
			'bec_filter_bathrooms_min',
			UnitFilterRequest::PARAM_BATHROOMS_MIN,
			\__('Bathrooms', 'booking-engine-connector'),
			$minStr,
			$options
		);
	}

	/**
	 * Single-select filter with popover UI (desktop value box + mobile trigger + bottom sheet).
	 *
	 * @param list<array{value: string, label: string}> $options
	 */
	private static function renderSelectPickerField(
		string $fieldSlug,
		string $selectId,
		string $paramName,
		string $labelText,
		string $currentValue,
		array $options
	): void {
		self::$pickerInstanceCount++;
		$instanceId  = self::$pickerInstanceCount;
		$labelId     = 'bec_picker_label_' . $instanceId;
		$panelId     = 'bec_picker_panel_' . $instanceId;
		$radioGroup  = 'bec_picker_rg_' . $instanceId;

		$anyLabel     = \__('Any', 'booking-engine-connector');
		$doneLabel    = \esc_html__('Done', 'booking-engine-connector');
		$closeAria    = \esc_attr__(
			/* translators: %s filter name, e.g. "Order", "Rooms". */
			\sprintf(\__('Close %s picker', 'booking-engine-connector'), $labelText)
		);

		$displayLabel = self::pickerDisplayLabel($labelText, $currentValue, $options, $anyLabel);
		$labelClass   = 'bec-unit-filters__label';
		if (self::$hideLabels) {
			$labelClass .= ' screen-reader-text';
		}

		echo '<div class="bec-unit-filters__field bec-unit-filters__field--' . \esc_attr($fieldSlug) . ' bec-unit-filters__picker" data-bec-select-root';
		if (self::$hideLabels) {
			echo ' data-bec-picker-placeholder="' . \esc_attr($labelText) . '"';
		}
		echo '>';

		echo '<label class="' . \esc_attr($labelClass) . '" id="' . \esc_attr($labelId) . '" for="' . \esc_attr($selectId) . '">';
		echo \esc_html($labelText);
		echo '</label>';

		echo '<div class="bec-unit-filters__picker-wrap">';

		echo '<select class="bec-unit-filters__control bec-unit-filters__picker-native" name="' . \esc_attr($paramName) . '" id="' . \esc_attr($selectId) . '" data-bec-picker-native>';
		foreach ($options as $opt) {
			$optionLabel = $opt['label'];
			if (self::$hideLabels && $opt['value'] === '') {
				$optionLabel = $labelText;
			}
			echo '<option value="' . \esc_attr($opt['value']) . '" ' . \selected($currentValue, $opt['value'], false) . '>';
			echo \esc_html($optionLabel);
			echo '</option>';
		}
		echo '</select>';

		echo '<button type="button" class="bec-unit-filters__picker-trigger" data-bec-picker-trigger ';
		echo 'aria-haspopup="dialog" aria-expanded="false" aria-controls="' . \esc_attr($panelId) . '">';
		echo '<span class="bec-unit-filters__picker-trigger-text" data-bec-picker-trigger-text>';
		echo \esc_html($displayLabel);
		echo '</span>';
		echo '<span class="bec-unit-filters__picker-trigger-caret" aria-hidden="true"></span>';
		echo '</button>';

		echo '<div class="bec-unit-filters__picker-value" data-bec-picker-value data-bec-picker-value-trigger ';
		echo 'role="button" tabindex="0" aria-haspopup="dialog" aria-expanded="false" aria-controls="' . \esc_attr($panelId) . '">';
		echo '<span class="bec-unit-filters__picker-value-text" data-bec-picker-value-text>';
		echo \esc_html($displayLabel);
		echo '</span>';
		echo '<span class="bec-unit-filters__picker-value-caret" aria-hidden="true"></span>';
		echo '</div>';

		echo '<div class="bec-unit-filters__picker-backdrop" data-bec-picker-backdrop hidden></div>';

		echo '<div class="bec-unit-filters__picker-panel" id="' . \esc_attr($panelId) . '" data-bec-picker-panel role="dialog" aria-modal="false" aria-labelledby="' . \esc_attr($labelId) . '">';

		echo '<div class="bec-unit-filters__picker-panel-header">';
		echo '<span class="bec-unit-filters__picker-panel-title">' . \esc_html($labelText) . '</span>';
		echo '<button type="button" class="bec-unit-filters__picker-close" data-bec-picker-close aria-label="' . $closeAria . '">';
		echo '<span aria-hidden="true">&times;</span>';
		echo '</button>';
		echo '</div>';

		echo '<ul class="bec-unit-filters__picker-list" data-bec-picker-list role="listbox">';
		foreach ($options as $opt) {
			$value   = $opt['value'];
			$label   = $opt['label'];
			$inputId = $selectId . '_picker_' . $instanceId . '_' . \sanitize_html_class($value !== '' ? $value : 'any');
			$checked = $currentValue === $value;

			echo '<li class="bec-unit-filters__picker-option" data-bec-picker-option>';
			echo '<label class="bec-unit-filters__picker-option-label" for="' . \esc_attr($inputId) . '">';
			echo '<input type="radio" id="' . \esc_attr($inputId) . '" name="' . \esc_attr($radioGroup) . '" value="' . \esc_attr($value) . '" data-label="' . \esc_attr($label) . '" ' . \checked($checked, true, false) . ' />';
			echo '<span class="bec-unit-filters__picker-option-box" aria-hidden="true"></span>';
			echo '<span class="bec-unit-filters__picker-option-text">' . \esc_html($label) . '</span>';
			echo '</label>';
			echo '</li>';
		}
		echo '</ul>';

		echo '<div class="bec-unit-filters__picker-panel-footer">';
		echo '<button type="button" class="bec-unit-filters__picker-done" data-bec-picker-done>' . $doneLabel . '</button>';
		echo '</div>';

		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Renders the amenities facet as real checkboxes wrapped in enhancement-friendly markup:
	 * a trigger button + desktop popover / mobile bottom sheet driven by
	 * `assets/public-unit-filters.js`. No-JS clients still see and submit the bare checkbox list.
	 *
	 * @param list<array{key: string, label: string}> $choices
	 */
	private static function renderAmenitiesField(UnitFilterRequest $request, array $choices): void
	{
		$selected = \array_fill_keys($request->getAmenityKeys(), true);

		self::$amenitiesInstanceCount++;
		$instanceId  = self::$amenitiesInstanceCount;
		$legendId    = 'bec_amenities_legend_' . $instanceId;
		$panelId     = 'bec_amenities_panel_' . $instanceId;
		$selectedCount = 0;
		foreach ($choices as $choice) {
			if (isset($selected[ $choice['key'] ])) {
				$selectedCount++;
			}
		}

		$placeholderRaw    = \__('Pick desired amenities', 'booking-engine-connector');
		$amenitiesLabelRaw = \__('Amenities', 'booking-engine-connector');
		$clearLabel        = \esc_html__('Clear', 'booking-engine-connector');
		$doneLabel         = \esc_html__('Done', 'booking-engine-connector');
		$closeAriaLabel    = \esc_attr__('Close amenities picker', 'booking-engine-connector');
		if ($selectedCount > 0) {
			$triggerLabelRaw = \sprintf(
				/* translators: 1: number of selected amenities, 2: total number of amenity choices. */
				\_n(
					'%1$d of %2$d selected',
					'%1$d of %2$d selected',
					$selectedCount,
					'booking-engine-connector'
				),
				$selectedCount,
				\count($choices)
			);
		} else {
			$triggerLabelRaw = self::$hideLabels ? $amenitiesLabelRaw : $placeholderRaw;
		}

		$legendClass = 'bec-unit-filters__label';
		if (self::$hideLabels) {
			$legendClass .= ' screen-reader-text';
		}

		echo '<fieldset class="bec-unit-filters__field bec-unit-filters__field--amenities" data-bec-amenities-root aria-labelledby="' . \esc_attr($legendId) . '"';
		if (self::$hideLabels) {
			echo ' data-bec-amenities-placeholder="' . \esc_attr($amenitiesLabelRaw) . '"';
		}
		echo '>';

		echo '<legend class="' . \esc_attr($legendClass) . '" id="' . \esc_attr($legendId) . '">' . \esc_html($amenitiesLabelRaw) . '</legend>';

		echo '<div class="bec-unit-filters__amenities">';

		echo '<button type="button" class="bec-unit-filters__amenities-trigger" ';
		echo 'data-bec-amenities-trigger ';
		echo 'aria-haspopup="dialog" aria-expanded="false" aria-controls="' . \esc_attr($panelId) . '">';
		echo '<span class="bec-unit-filters__amenities-trigger-text" data-bec-amenities-trigger-text>';
		echo \esc_html($triggerLabelRaw);
		echo '</span>';
		echo '<span class="bec-unit-filters__amenities-trigger-caret" aria-hidden="true"></span>';
		echo '</button>';

		echo '<div class="bec-unit-filters__amenities-backdrop" data-bec-amenities-backdrop hidden></div>';

		echo '<div class="bec-unit-filters__amenities-panel" id="' . \esc_attr($panelId) . '" data-bec-amenities-panel role="dialog" aria-modal="false" aria-labelledby="' . \esc_attr($legendId) . '">';

		echo '<div class="bec-unit-filters__amenities-panel-header">';
		echo '<span class="bec-unit-filters__amenities-panel-title">' . \esc_html($amenitiesLabelRaw) . '</span>';
		echo '<button type="button" class="bec-unit-filters__amenities-clear" data-bec-amenities-clear>' . $clearLabel . '</button>';
		echo '<button type="button" class="bec-unit-filters__amenities-close" data-bec-amenities-close aria-label="' . $closeAriaLabel . '">';
		echo '<span aria-hidden="true">&times;</span>';
		echo '</button>';
		echo '</div>';

		echo '<ul class="bec-unit-filters__amenities-list" data-bec-amenities-list role="listbox" aria-multiselectable="true">';
		foreach ($choices as $choice) {
			$key     = $choice['key'];
			$label   = $choice['label'];
			$checked = isset($selected[ $key ]);
			$inputId = 'bec_amenity_' . $instanceId . '_' . \sanitize_html_class($key);

			echo '<li class="bec-unit-filters__amenities-option" data-bec-amenities-option>';
			echo '<label class="bec-unit-filters__amenities-option-label" for="' . \esc_attr($inputId) . '">';
			echo '<input type="checkbox" id="' . \esc_attr($inputId) . '" name="' . \esc_attr(UnitFilterRequest::PARAM_AMENITIES) . '[]" value="' . \esc_attr($key) . '" data-label="' . \esc_attr($label) . '" ' . \checked($checked, true, false) . ' />';
			echo '<span class="bec-unit-filters__amenities-option-box" aria-hidden="true"></span>';
			echo '<span class="bec-unit-filters__amenities-option-text">' . \esc_html($label) . '</span>';
			echo '</label>';
			echo '</li>';
		}
		echo '</ul>';

		echo '<div class="bec-unit-filters__amenities-panel-footer">';
		echo '<button type="button" class="bec-unit-filters__amenities-done" data-bec-amenities-done>' . $doneLabel . '</button>';
		echo '</div>';

		echo '</div>';
		echo '</div>';
		echo '</fieldset>';
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

	/**
	 * @param list<array{value: string, label: string}> $options
	 */
	private static function pickerDisplayLabel(
		string $fieldLabel,
		string $currentValue,
		array $options,
		string $anyLabel
	): string {
		if ($currentValue === '' && self::$hideLabels) {
			return $fieldLabel;
		}

		foreach ($options as $opt) {
			if ($opt['value'] === $currentValue) {
				return $opt['label'];
			}
		}

		return $anyLabel;
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
