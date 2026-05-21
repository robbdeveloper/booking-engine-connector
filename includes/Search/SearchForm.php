<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Providers\Contracts\SearchGuestFieldMode;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Styling\StylingSettings;

/**
 * Renders the availability search form (GET → bec_* query parameters).
 *
 * Hooks: {@see SearchTemplateHooks} and `bec_before_search_form` / `bec_after_search_form`.
 */
final class SearchForm
{
	public const POPOVER_PLACEMENT_AUTO   = 'auto';
	public const POPOVER_PLACEMENT_TOP    = 'top';
	public const POPOVER_PLACEMENT_BOTTOM = 'bottom';

	/**
	 * @param array{
	 *   context?: string,
	 *   action?: string,
	 *   form_id?: string,
	 *   html_class?: string,
	 *   show_submit?: bool,
	 *   popover_placement?: string,
	 * } $args
	 */
	public static function render(array $args = []): void
	{
		$context   = isset($args['context']) ? (string) $args['context'] : 'default';
		$action    = isset($args['action']) ? (string) $args['action'] : '';
		$formId    = isset($args['form_id']) ? (string) $args['form_id'] : 'bec-search-form';
		$htmlClass = isset($args['html_class']) ? (string) $args['html_class'] : 'bec-search-form';
		$showSubmit = ! isset( $args['show_submit'] ) || (bool) $args['show_submit'];

		if ($action === '') {
			$action = (string) \apply_filters('bec_search_form_action', '', $context);
		}
		if ($action === '') {
			$slug = UnitPostType::getSlug();
			if (\is_post_type_archive($slug)) {
				$link = \get_post_type_archive_link($slug);
				$action = $link !== false ? (string) $link : \home_url('/');
			} elseif (\is_singular()) {
				$action = (string) \get_permalink();
			} else {
				$action = \home_url('/');
			}
		}

		$ctx   = SearchContext::fromRequest();
		$error = null;
		if ($ctx->isComplete()) {
			$error = SearchValidator::validate($ctx);
		}

		$checkin  = \esc_attr($ctx->getCheckin());
		$checkout = \esc_attr($ctx->getCheckout());
		$adults   = $ctx->getAdults() > 0 ? (string) $ctx->getAdults() : '';
		$children = (string) \max(0, $ctx->getChildren());
		$totalPax = $ctx->getAdults() + $ctx->getChildren();

		$guestFieldMode = (string) \apply_filters(
			'bec_search_guest_field_mode',
			ProviderRegistry::getProvider()->getSearchGuestFieldMode(),
			$ctx
		);
		if ($guestFieldMode !== SearchGuestFieldMode::TOTAL && $guestFieldMode !== SearchGuestFieldMode::BREAKDOWN) {
			$guestFieldMode = SearchGuestFieldMode::BREAKDOWN;
		}

		$needsChildAges = $guestFieldMode === SearchGuestFieldMode::BREAKDOWN
			&& (bool) \apply_filters(
				'bec_provider_requires_children_ages',
				ProviderRegistry::getProvider()->requiresChildrenAges(),
				$ctx
			);

		if ($guestFieldMode === SearchGuestFieldMode::TOTAL) {
			$totalStr = $totalPax > 0 ? (string) $totalPax : '';
			$fields   = [
				SearchContext::PARAM_CHECKIN  => [
					'label' => \__('Check-in', 'booking-engine-connector'),
					'type'  => 'date',
					'value' => $checkin,
				],
				SearchContext::PARAM_CHECKOUT => [
					'label' => \__('Check-out', 'booking-engine-connector'),
					'type'  => 'date',
					'value' => $checkout,
				],
				SearchContext::PARAM_TOTAL_GUESTS => [
					'label' => \__('Guests', 'booking-engine-connector'),
					'type'  => 'number',
					'value' => $totalStr,
					'min'   => '1',
				],
			];
		} else {
			$fields = [
				SearchContext::PARAM_CHECKIN  => [
					'label' => \__('Check-in', 'booking-engine-connector'),
					'type'  => 'date',
					'value' => $checkin,
				],
				SearchContext::PARAM_CHECKOUT => [
					'label' => \__('Check-out', 'booking-engine-connector'),
					'type'  => 'date',
					'value' => $checkout,
				],
				SearchContext::PARAM_ADULTS   => [
					'label' => \__('Adults', 'booking-engine-connector'),
					'type'  => 'number',
					'value' => $adults,
					'min'   => '1',
				],
				SearchContext::PARAM_CHILDREN => [
					'label' => \__('Children', 'booking-engine-connector'),
					'type'  => 'number',
					'value' => $children,
					'min'   => '0',
				],
			];
		}

		/**
		 * @var array<string, array<string, string>> $fields
		 */
		$fields = \apply_filters('bec_search_form_fields', $fields, $context, $ctx);

		$preset = (string) \apply_filters(
			'bec_search_form_preset',
			StylingSettings::getSearchPreset(),
			$context,
			$ctx
		);
		if (! \in_array($preset, [StylingSettings::SEARCH_PRESET_ENHANCED, StylingSettings::SEARCH_PRESET_CLASSIC], true)) {
			$preset = StylingSettings::SEARCH_PRESET_ENHANCED;
		}
		$useEnhanced = $preset === StylingSettings::SEARCH_PRESET_ENHANCED;
		$useEnhanced = (bool) \apply_filters('bec_search_form_use_enhanced_layout', $useEnhanced, $context, $ctx);

		$popoverPlacement = self::POPOVER_PLACEMENT_AUTO;
		if (isset($args['popover_placement'])) {
			$popoverPlacement = self::normalizePopoverPlacement((string) $args['popover_placement']);
		}
		$popoverPlacement = (string) \apply_filters(
			'bec_search_form_popover_placement',
			$popoverPlacement,
			$context,
			$ctx
		);
		$popoverPlacement = self::normalizePopoverPlacement($popoverPlacement);

		if ($useEnhanced) {
			self::renderEnhanced(
				$formId,
				$htmlClass,
				$action,
				$error,
				$fields,
				$ctx,
				$needsChildAges,
				$checkin,
				$checkout,
				$adults,
				$children,
				$guestFieldMode,
				$showSubmit,
				$popoverPlacement
			);

			return;
		}

		self::renderClassic(
			$formId,
			$htmlClass,
			$action,
			$error,
			$fields,
			$ctx,
			$needsChildAges,
			$guestFieldMode,
			$showSubmit
		);
	}

	/**
	 * @param array<string, array<string, string>> $fields
	 */
	private static function renderClassic(
		string $formId,
		string $htmlClass,
		string $action,
		?\WP_Error $error,
		array $fields,
		SearchContext $ctx,
		bool $needsChildAges,
		string $guestFieldMode,
		bool $showSubmit = true
	): void {
		echo '<div class="' . \esc_attr($htmlClass) . '-wrap">';
		echo '<form class="' . \esc_attr($htmlClass) . '" id="' . \esc_attr($formId) . '" method="get" action="' . \esc_url($action) . '" data-bec-guest-mode="' . \esc_attr($guestFieldMode) . '">';

		if ($error instanceof \WP_Error) {
			echo '<p class="bec-search-form__error" role="alert">' . \esc_html($error->get_error_message()) . '</p>';
		}

		foreach ($fields as $name => $field) {
			$label = isset($field['label']) ? (string) $field['label'] : '';
			$type  = isset($field['type']) ? (string) $field['type'] : 'text';
			$val   = isset($field['value']) ? (string) $field['value'] : '';
			$min   = isset($field['min']) ? (string) $field['min'] : '';

			echo '<p class="bec-search-form__field bec-search-form__field--' . \esc_attr(\sanitize_key($name)) . '">';
			echo '<label for="' . \esc_attr($formId . '-' . $name) . '">' . \esc_html($label) . '</label> ';
			echo '<input id="' . \esc_attr($formId . '-' . $name) . '" name="' . \esc_attr($name) . '" type="' . \esc_attr($type) . '" value="' . \esc_attr($val) . '"';
			if ($min !== '') {
				echo ' min="' . \esc_attr($min) . '"';
			}
			echo ' />';
			echo '</p>';
		}

		if ($needsChildAges) {
			self::renderClassicChildAges($formId, $ctx);
		}

		if ($showSubmit) {
			echo '<p class="bec-search-form__submit"><button type="submit" class="bec-search-form__button">' . \esc_html__('Search availability', 'booking-engine-connector') . '</button></p>';
		}
		echo '</form>';
		echo '</div>';
	}

	private static function renderClassicChildAges(string $formId, SearchContext $ctx): void
	{
		$ages     = $ctx->getChildrenAges();
		$n        = \max(0, $ctx->getChildren());
		$maxSlots = (int) \apply_filters('bec_search_max_child_age_slots', 8, $ctx);
		if ($maxSlots < 1) {
			$maxSlots = 8;
		}
		$show = \min($n, $maxSlots);
		echo '<div class="bec-search-form__child-ages" data-bec-child-ages-root="1" data-bec-form-id="' . \esc_attr($formId) . '" data-bec-max-child-age-slots="' . (int) $maxSlots . '">';
		for ($i = 0; $i < $show; $i++) {
			$ageVal = isset($ages[$i]) ? (string) (int) $ages[$i] : '';
			/* translators: %d: child index (1-based) */
			$lbl = \sprintf(\__('Child %d age', 'booking-engine-connector'), $i + 1);
			echo '<p class="bec-search-form__field bec-search-form__field--bec-child-age" data-bec-child-age-index="' . (int) $i . '">';
			echo '<label for="' . \esc_attr($formId . '-child-age-' . $i) . '">' . \esc_html($lbl) . '</label> ';
			echo '<input id="' . \esc_attr($formId . '-child-age-' . $i) . '" name="' . \esc_attr(SearchContext::PARAM_CHILD_AGE) . '[]" type="number" min="0" max="17" value="' . \esc_attr($ageVal) . '" />';
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string, array<string, string>> $fields
	 */
	private static function renderEnhanced(
		string $formId,
		string $htmlClass,
		string $action,
		?\WP_Error $error,
		array $fields,
		SearchContext $ctx,
		bool $needsChildAges,
		string $checkin,
		string $checkout,
		string $adults,
		string $children,
		string $guestFieldMode,
		bool $showSubmit = true,
		string $popoverPlacement = self::POPOVER_PLACEMENT_AUTO
	): void {
		$popoverPlacement = self::normalizePopoverPlacement($popoverPlacement);

		$labelCheckin  = isset($fields[SearchContext::PARAM_CHECKIN]['label'])
			? (string) $fields[SearchContext::PARAM_CHECKIN]['label']
			: \__('Check-in', 'booking-engine-connector');
		$labelCheckout = isset($fields[SearchContext::PARAM_CHECKOUT]['label'])
			? (string) $fields[SearchContext::PARAM_CHECKOUT]['label']
			: \__('Check-out', 'booking-engine-connector');
		$labelAdults   = isset($fields[SearchContext::PARAM_ADULTS]['label'])
			? (string) $fields[SearchContext::PARAM_ADULTS]['label']
			: \__('Adults', 'booking-engine-connector');
		$labelChildren = isset($fields[SearchContext::PARAM_CHILDREN]['label'])
			? (string) $fields[SearchContext::PARAM_CHILDREN]['label']
			: \__('Children', 'booking-engine-connector');
		$labelTotalGuests = isset($fields[SearchContext::PARAM_TOTAL_GUESTS]['label'])
			? (string) $fields[SearchContext::PARAM_TOTAL_GUESTS]['label']
			: \__('Guests', 'booking-engine-connector');

		$maxSlots = (int) \apply_filters('bec_search_max_child_age_slots', 8, $ctx);
		if ($maxSlots < 1) {
			$maxSlots = 8;
		}
		$maxAdults   = (int) \apply_filters('bec_search_max_adults', 30, $ctx);
		$maxChildren = (int) \apply_filters('bec_search_max_children', $maxSlots, $ctx);
		$maxTotal    = (int) \apply_filters('bec_search_max_total_guests', 30, $ctx);
		if ($maxAdults < 1) {
			$maxAdults = 30;
		}
		if ($maxChildren < 0) {
			$maxChildren = $maxSlots;
		}
		if ($maxTotal < 1) {
			$maxTotal = 30;
		}

		$adultsVal = $adults !== '' ? $adults : '1';
		$totalPax  = $ctx->getAdults() + $ctx->getChildren();
		$totalVal  = $totalPax > 0 ? (string) $totalPax : '1';
		$guestsId  = $formId . '-popover-guests';

		$guestsLbl = \esc_attr(\__('Guests', 'booking-engine-connector'));

		echo '<div class="' . \esc_attr($htmlClass) . '-wrap ' . \esc_attr($htmlClass) . '-wrap--enhanced">';
		echo '<form class="' . \esc_attr($htmlClass) . ' ' . \esc_attr($htmlClass) . '--enhanced" id="' . \esc_attr($formId) . '" method="get" action="' . \esc_url($action) . '" data-bec-guest-mode="' . \esc_attr($guestFieldMode) . '" data-bec-popover-placement="' . \esc_attr($popoverPlacement) . '">';

		if ($error instanceof \WP_Error) {
			echo '<p class="bec-search-form__error" role="alert">' . \esc_html($error->get_error_message()) . '</p>';
		}

		echo '<div class="bec-search-form__bar" role="group" aria-label="' . \esc_attr__('Search stays', 'booking-engine-connector') . '">';

		echo '<div class="bec-search-form__control bec-search-form__control--dates bec-search-form__control--daterange" data-bec-section-heading="' . \esc_attr(\__('Select dates', 'booking-engine-connector')) . '" data-bec-daterange>';
		echo '<input type="hidden" name="' . \esc_attr(SearchContext::PARAM_CHECKIN) . '" id="' . \esc_attr($formId . '-' . SearchContext::PARAM_CHECKIN) . '" value="' . $checkin . '" autocomplete="off" />';
		echo '<input type="hidden" name="' . \esc_attr(SearchContext::PARAM_CHECKOUT) . '" id="' . \esc_attr($formId . '-' . SearchContext::PARAM_CHECKOUT) . '" value="' . $checkout . '" autocomplete="off" />';
		echo '<button type="button" class="bec-search-form__date-split" id="' . \esc_attr($formId . '-daterange-trigger') . '" aria-expanded="false" aria-haspopup="dialog">';
		echo '<div class="bec-search-form__date-half bec-search-form__date-half--checkin">';
		echo '<span class="bec-search-form__date-label">' . \esc_html($labelCheckin) . '</span>';
		echo '<div class="bec-search-form__date-half-body">';
		echo '<span class="bec-search-form__date-day" data-bec-part="day-in">—</span>';
		echo '<div class="bec-search-form__date-meta">';
		echo '<span class="bec-search-form__date-my" data-bec-part="my-in"></span>';
		echo '<span class="bec-search-form__date-dow" data-bec-part="dow-in"></span>';
		echo '</div></div></div>';
		echo '<div class="bec-search-form__date-divider" aria-hidden="true"></div>';
		echo '<div class="bec-search-form__date-half bec-search-form__date-half--checkout">';
		echo '<span class="bec-search-form__date-label">' . \esc_html($labelCheckout) . '</span>';
		echo '<div class="bec-search-form__date-half-body">';
		echo '<span class="bec-search-form__date-day" data-bec-part="day-out">—</span>';
		echo '<div class="bec-search-form__date-meta">';
		echo '<span class="bec-search-form__date-my" data-bec-part="my-out"></span>';
		echo '<span class="bec-search-form__date-dow" data-bec-part="dow-out"></span>';
		echo '</div></div></div>';
		echo '</button>';
		echo '</div>';

		echo '<div class="bec-search-form__control bec-search-form__control--guests">';
		echo '<button type="button" class="bec-search-form__trigger" id="' . \esc_attr($formId . '-trigger-guests') . '" aria-expanded="false" aria-controls="' . \esc_attr($guestsId) . '">';
		echo '<span class="bec-search-form__trigger-label">' . \esc_html__('Guests', 'booking-engine-connector') . '</span>';
		echo '<span class="bec-search-form__trigger-value" data-bec-guest-summary data-bec-empty="' . $guestsLbl . '"></span>';
		echo '</button>';
		echo '<div class="bec-search-form__panel" id="' . \esc_attr($guestsId) . '" role="dialog" aria-modal="true" aria-labelledby="' . \esc_attr($formId . '-trigger-guests') . '" hidden tabindex="-1">';
		echo '<div class="bec-search-form__panel-inner">';

		if ($guestFieldMode === SearchGuestFieldMode::TOTAL) {
			echo '<div class="bec-search-form__row">';
			echo '<span class="bec-search-form__row-label">' . \esc_html($labelTotalGuests) . '</span>';
			echo '<div class="bec-search-form__stepper" data-bec-stepper-for="' . \esc_attr(SearchContext::PARAM_TOTAL_GUESTS) . '">';
			echo '<button type="button" class="bec-search-form__step-btn" data-bec-step="-1" aria-label="' . \esc_attr__('Decrease guests', 'booking-engine-connector') . '">−</button>';
			echo '<input id="' . \esc_attr($formId . '-' . SearchContext::PARAM_TOTAL_GUESTS) . '" form="' . \esc_attr($formId) . '" name="' . \esc_attr(SearchContext::PARAM_TOTAL_GUESTS) . '" type="number" min="1" max="' . (int) $maxTotal . '" step="1" value="' . \esc_attr($totalVal) . '" inputmode="numeric" />';
			echo '<button type="button" class="bec-search-form__step-btn" data-bec-step="1" aria-label="' . \esc_attr__('Increase guests', 'booking-engine-connector') . '">+</button>';
			echo '</div></div>';
		} else {
			echo '<div class="bec-search-form__row">';
			echo '<span class="bec-search-form__row-label">' . \esc_html($labelAdults) . '</span>';
			echo '<div class="bec-search-form__stepper" data-bec-stepper-for="' . \esc_attr(SearchContext::PARAM_ADULTS) . '">';
			echo '<button type="button" class="bec-search-form__step-btn" data-bec-step="-1" aria-label="' . \esc_attr__('Decrease adults', 'booking-engine-connector') . '">−</button>';
			echo '<input id="' . \esc_attr($formId . '-' . SearchContext::PARAM_ADULTS) . '" form="' . \esc_attr($formId) . '" name="' . \esc_attr(SearchContext::PARAM_ADULTS) . '" type="number" min="1" max="' . (int) $maxAdults . '" step="1" value="' . \esc_attr($adultsVal) . '" inputmode="numeric" />';
			echo '<button type="button" class="bec-search-form__step-btn" data-bec-step="1" aria-label="' . \esc_attr__('Increase adults', 'booking-engine-connector') . '">+</button>';
			echo '</div></div>';

			echo '<div class="bec-search-form__row">';
			echo '<span class="bec-search-form__row-label">' . \esc_html($labelChildren) . '</span>';
			echo '<div class="bec-search-form__stepper" data-bec-stepper-for="' . \esc_attr(SearchContext::PARAM_CHILDREN) . '">';
			echo '<button type="button" class="bec-search-form__step-btn" data-bec-step="-1" aria-label="' . \esc_attr__('Decrease children', 'booking-engine-connector') . '">−</button>';
			echo '<input id="' . \esc_attr($formId . '-' . SearchContext::PARAM_CHILDREN) . '" form="' . \esc_attr($formId) . '" name="' . \esc_attr(SearchContext::PARAM_CHILDREN) . '" type="number" min="0" max="' . (int) $maxChildren . '" step="1" value="' . \esc_attr($children) . '" inputmode="numeric" />';
			echo '<button type="button" class="bec-search-form__step-btn" data-bec-step="1" aria-label="' . \esc_attr__('Increase children', 'booking-engine-connector') . '">+</button>';
			echo '</div></div>';

			if ($needsChildAges) {
				$ages = $ctx->getChildrenAges();
				$n    = \max(0, $ctx->getChildren());
				$show = \min($n, $maxSlots);
				echo '<div class="bec-search-form__child-ages bec-search-form__child-ages--enhanced" data-bec-child-ages-root="1" data-bec-form-id="' . \esc_attr($formId) . '" data-bec-max-child-age-slots="' . (int) $maxSlots . '">';
				for ($i = 0; $i < $show; $i++) {
					$ageVal = isset($ages[$i]) ? (string) (int) $ages[$i] : '';
					/* translators: %d: child index (1-based) */
					$lbl = \sprintf(\__('Child %d age', 'booking-engine-connector'), $i + 1);
					echo '<div class="bec-search-form__child-age" data-bec-child-age-index="' . (int) $i . '">';
					echo '<label for="' . \esc_attr($formId . '-child-age-' . $i) . '">' . \esc_html($lbl) . '</label>';
					echo '<select id="' . \esc_attr($formId . '-child-age-' . $i) . '" form="' . \esc_attr($formId) . '" name="' . \esc_attr(SearchContext::PARAM_CHILD_AGE) . '[]">';
					echo '<option value=""';
					if ($ageVal === '') {
						echo ' selected="selected"';
					}
					echo '>' . \esc_html__('Age', 'booking-engine-connector') . '</option>';
					for ($age = 0; $age <= 17; $age++) {
						echo '<option value="' . (int) $age . '"';
						if ($ageVal !== '' && (int) $ageVal === $age) {
							echo ' selected="selected"';
						}
						echo '>' . \esc_html((string) $age) . '</option>';
					}
					echo '</select></div>';
				}
				echo '</div>';
			}
		}

		echo '<div class="bec-search-form__panel-footer" data-bec-guest-actions="1">';
		echo '<button type="button" class="bec-search-form__panel-footer-btn bec-search-form__panel-footer-btn--secondary" data-bec-guest-dismiss="cancel">' . \esc_html__('Cancel', 'booking-engine-connector') . '</button>';
		echo '<button type="button" class="bec-search-form__panel-footer-btn bec-search-form__panel-footer-btn--primary" data-bec-guest-dismiss="apply">' . \esc_html__('Apply', 'booking-engine-connector') . '</button>';
		echo '</div>';

		echo '</div></div></div>';

		if ($showSubmit) {
			echo '<div class="bec-search-form__control bec-search-form__control--submit">';
			echo '<button type="submit" class="bec-search-form__button">' . \esc_html__('Search availability', 'booking-engine-connector') . '</button>';
			echo '</div>';
		}

		echo '</div>';
		echo '</form>';
		echo '<div class="bec-search-form__backdrop" hidden aria-hidden="true"></div>';
		echo '</div>';
	}

	/**
	 * @return self::POPOVER_PLACEMENT_*
	 */
	public static function normalizePopoverPlacement(string $raw): string
	{
		$raw = \sanitize_key(\strtolower(\trim($raw)));
		$allowed = [
			self::POPOVER_PLACEMENT_AUTO,
			self::POPOVER_PLACEMENT_TOP,
			self::POPOVER_PLACEMENT_BOTTOM,
		];

		return \in_array($raw, $allowed, true) ? $raw : self::POPOVER_PLACEMENT_AUTO;
	}
}
