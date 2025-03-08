<?php
/**
 * Retain recent bbPress replies, along with their topics and forums.
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types=1 );

namespace PMC\WP_Local_Data_CLI\Query_Args;

use PMC\WP_Local_Data_CLI\Query_Args;

/**
 * Class bbPress.
 */
// phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital, Squiz.Commenting.ClassComment.Missing
final class bbPress extends Query_Args {
	/**
	 * Backfill is not required as we query from replies and use their meta to
	 * capture the topic and forum.
	 *
	 * @var bool
	 */
	public static bool $skip_backfill = true;

	/**
	 * Build array of `WP_Query` arguments used to retrieve IDs to retain.
	 *
	 * @return array
	 */
	public static function get_query_args(): array {
		// Short-circuit this handler when reply post type is unknown.
		if ( ! function_exists( 'bbp_get_reply_post_type' ) ) {
			return [
				'post_type'  => 'abcdef0123456789',
				'date_query' => [
					[
						'after' => '+500 years',
					],
				],
			];
		}

		return [
			'post_type'  => bbp_get_reply_post_type(),
			'date_query' => [
				[
					'after' => '-1 months',
				],
			],
		];
	}

	// Declaration must be compatible with overridden method.
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed, Squiz.Commenting.FunctionComment.Missing, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	public static function get_linked_ids( $posts, $post_query ): array {
		$ancestors = array_unique( array_filter( array_merge(
					array_filter( $post_query->get_posts_meta( $posts, '_bbp_topic_id' ) ),
					array_filter( $post_query->get_posts_meta( $posts, '_bbp_forum_id' ) )
				)
			)
		);

		return $post_query->get_posts($ancestors);
	}
}
