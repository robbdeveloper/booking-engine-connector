<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Template integration: optional auto-form on single units, archive loop hooks.
 */
final class SearchTemplateHooks
{
	/** @var array<int, true> Post IDs that already received the auto-prepended search form this request */
	private static array $prependedSearchFormForPostId = [];

	public static function register(): void
	{
		\add_action('wp', [self::class, 'maybeAutoAppendFormOnSingleUnit']);
		\add_action('loop_start', [self::class, 'onLoopStart'], 10, 1);
		\add_action('loop_end', [self::class, 'onLoopEnd'], 10, 1);
	}

	public static function maybeAutoAppendFormOnSingleUnit(): void
	{
		if (! \is_singular()) {
			return;
		}

		$post = \get_queried_object();
		if (! $post instanceof \WP_Post || $post->post_type !== UnitPostType::getSlug()) {
			return;
		}

		$allow = (bool) \apply_filters(
			'bec_auto_append_search_form_on_single_unit',
			SearchSettings::isAutoAppendSearchFormOnSingleUnit()
		);
		if (! $allow) {
			return;
		}

		\add_filter('the_content', [self::class, 'prependSearchFormToContent'], 4);
	}

	public static function prependSearchFormToContent(string $content): string
	{
		if (! \in_the_loop() || ! \is_main_query()) {
			return $content;
		}

		if (\get_post_type() !== UnitPostType::getSlug()) {
			return $content;
		}

		$postId = (int) \get_the_ID();
		if ($postId <= 0 || isset(self::$prependedSearchFormForPostId[$postId])) {
			return $content;
		}
		self::$prependedSearchFormForPostId[$postId] = true;

		\ob_start();
		\do_action('bec_before_search_form', 'single');
		SearchForm::render(['context' => 'single']);
		\do_action('bec_after_search_form', 'single');
		$form = (string) \ob_get_clean();

		return $form . $content;
	}

	public static function onLoopStart(\WP_Query $query): void
	{
		if (! $query->is_main_query() || ! $query->is_post_type_archive(UnitPostType::getSlug())) {
			return;
		}

		\do_action('bec_before_unit_archive_loop', $query);
	}

	public static function onLoopEnd(\WP_Query $query): void
	{
		if (! $query->is_main_query() || ! $query->is_post_type_archive(UnitPostType::getSlug())) {
			return;
		}

		\do_action('bec_after_unit_archive_loop', $query);
	}
}
