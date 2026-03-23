<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Providers\ProviderRegistry;

/**
 * Renders the availability search form (GET → bec_* query parameters).
 *
 * Hooks: {@see SearchTemplateHooks} and `bec_before_search_form` / `bec_after_search_form`.
 */
final class SearchForm
{
	/**
	 * @param array{
	 *   context?: string,
	 *   action?: string,
	 *   form_id?: string,
	 *   html_class?: string,
	 * } $args
	 */
	public static function render(array $args = []): void
	{
		$context  = isset($args['context']) ? (string) $args['context'] : 'default';
		$action   = isset($args['action']) ? (string) $args['action'] : '';
		$formId   = isset($args['form_id']) ? (string) $args['form_id'] : 'bec-search-form';
		$htmlClass = isset($args['html_class']) ? (string) $args['html_class'] : 'bec-search-form';

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

		$needsChildAges = (bool) \apply_filters(
			'bec_provider_requires_children_ages',
			ProviderRegistry::getProvider()->requiresChildrenAges(),
			$ctx
		);

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

		/**
		 * @var array<string, array<string, string>> $fields
		 */
		$fields = \apply_filters('bec_search_form_fields', $fields, $context, $ctx);

		echo '<div class="' . \esc_attr($htmlClass) . '-wrap">';
		echo '<form class="' . \esc_attr($htmlClass) . '" id="' . \esc_attr($formId) . '" method="get" action="' . \esc_url($action) . '">';

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
			$ages     = $ctx->getChildrenAges();
			$n        = \max(0, $ctx->getChildren());
			$maxSlots = (int) \apply_filters('bec_search_max_child_age_slots', 8, $ctx);
			if ($maxSlots < 1) {
				$maxSlots = 8;
			}
			echo '<div class="bec-search-form__child-ages">';
			for ($i = 0; $i < $maxSlots; $i++) {
				$active = $i < $n;
				$ageVal = isset($ages[$i]) ? (string) (int) $ages[$i] : '';
				/* translators: %d: child index (1-based) */
				$lbl = \sprintf(\__('Child %d age', 'booking-engine-connector'), $i + 1);
				echo '<p class="bec-search-form__field bec-search-form__field--bec-child-age" data-bec-child-age-index="' . (int) $i . '"';
				if (! $active) {
					echo ' hidden';
				}
				echo '>';
				echo '<label for="' . \esc_attr($formId . '-child-age-' . $i) . '">' . \esc_html($lbl) . '</label> ';
				echo '<input id="' . \esc_attr($formId . '-child-age-' . $i) . '" name="' . \esc_attr(SearchContext::PARAM_CHILD_AGE) . '[]" type="number" min="0" max="17" value="' . \esc_attr($ageVal) . '"';
				if (! $active) {
					echo ' disabled';
				}
				echo ' />';
				echo '</p>';
			}
			echo '</div>';
			echo '<script>';
			echo '(function(){var f=document.getElementById(' . \wp_json_encode($formId) . ');if(!f)return;var w=f.querySelector(".bec-search-form__child-ages");var ch=f.querySelector("[name=\\"' . \esc_js(SearchContext::PARAM_CHILDREN) . '\\"]");if(!w||!ch)return;var rows=w.querySelectorAll("[data-bec-child-age-index]");function sync(){var n=parseInt(ch.value,10)||0;for(var i=0;i<rows.length;i++){var on=i<n;var inp=rows[i].querySelector("input");rows[i].hidden=!on;if(inp){inp.disabled=!on;if(!on)inp.value="";}}}sync();ch.addEventListener("input",sync);ch.addEventListener("change",sync);})();';
			echo '</script>';
		}

		echo '<p class="bec-search-form__submit"><button type="submit" class="bec-search-form__button">' . \esc_html__('Search availability', 'booking-engine-connector') . '</button></p>';
		echo '</form>';
		echo '</div>';
	}
}
