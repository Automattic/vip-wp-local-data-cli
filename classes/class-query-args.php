<?php
/**
 * Base class to determine what content to keep.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * phpcs:disable WordPress.DB.PreparedSQL
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types = 1 );

namespace PMC\WP_Local_Data_CLI;

use WP_Post;

/**
 * Class Query_Args.
 */
abstract class Query_Args {
	/**
	 * Whether or not the queried post type has linked IDs gathered via the
	 * `get_linked_ids()` method.
	 *
	 * @var bool
	 */
	public static bool $find_linked_ids = true;

	/**
	 * Whether or not to skip the backfill process. Typically this is used when
	 * a query's `post_type` is set to the special value "any" as backfill
	 * cannot be applied to such a query.
	 *
	 * @var bool
	 */
	public static bool $skip_backfill = false;

	/**
	 * Build array of `WP_Query` arguments used to retrieve IDs to retain.
	 *
	 * @return array
	 */
	abstract public static function get_query_args(): array;

	/**
	 * Modify query arguments to retrieve dependent data for IDs already
	 * gathered.
	 *
	 * @return array
	 */
	final public static function get_query_args_for_backfill(): array {
		global $wpdb;

		$args = static::get_query_args();

		/**
		 * Force query to fail if backfill is undesired or not possible.
		 */
		if ( static::$skip_backfill || 'any' === $args['post_type'] ) {
			return [
				'post_type' => 'pmc-local-data-skip',
			];
		}

		$ids = $wpdb->get_col(
			'SELECT ID FROM '
			. Init::TABLE_NAME
			. " WHERE post_type = '{$args['post_type']}';"
		);

		return [
			'post_type' => $args['post_type'],
			'post__in'  => $ids,
		];
	}

	/**
	 * Process dependent data associated with a given ID, such as a post's
	 * thumbnail.
	 *
	 * If post type has no dependent data, set `static::$find_linked_ids` to
	 * false to skip this method.
	 *
	 * @param WP_Post[] $posts
	 * @return WP_Post[]
	 */
	// Parameters are provided for implementing classes.
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, Squiz.Commenting.FunctionComment.Missing, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	public static function get_linked_ids( array $posts ): array {
		return [];
	}

	/**
	 * Capture a post object's thumbnail ID.
	 *
	 * @param array $ids     Array of IDs to retain.
	 * @param int   $post_id Post ID.
	 * @return void
	 */
	final protected static function _add_thumbnail_id(
		array &$ids,
		int $post_id
	): void {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( $thumbnail_id ) {
			$ids[] = [
				'ID'        => $thumbnail_id,
				'post_type' => 'attachment',
			];
		}
	}
}
