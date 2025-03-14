<?php
/**
 * Query the posts table to build list of IDs to retain based on provided
 * `WP_Query` arguments and an optional callback.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types=1 );

namespace PMC\WP_Local_Data_CLI;

use WP_CLI;
use WP_Post;
use WP_Query;

/**
 * Class Query.
 */
final class Query {

	/**
	 * @var Post_Query
	 */
	private Post_Query $_post_query;

	/**
	 * Query constructor.
	 *
	 * @param Query_Args $instance Instance of `Query_Args` class.
	 * @param bool $process_backfill Backfill recursive dependent IDs for
	 *                                     IDs already queried.
	 */
	public function __construct(
		Query_Args $instance,
		bool $process_backfill = false
	) {
		$this->_post_query = new Post_Query();
		$args              = $process_backfill
			? $instance::get_query_args_for_backfill()
			: $instance::get_query_args();

		if ( ! isset( $args['post_type'] ) ) {
			WP_CLI::error(
				'Invalid configuration: ' . wp_json_encode( $args )
			);
		}

		WP_CLI::line(
			sprintf(
				$process_backfill
					? ' * Backfilling IDs using `%1$s` query args.'
					: ' * Gathering IDs using `%1$s`.',
				str_replace(
					__NAMESPACE__ . '\\',
					'',
					$instance::class
				)
			)
		);

		$this->_query(
			$args,
			$instance::$find_linked_ids
				? [ $instance::class, 'get_linked_ids' ]
				: null,
		);
	}

	/**
	 * Gather IDs to retain.
	 *
	 * @param array $args Query arguments.
	 * @param array|null $callback Callback to apply to found IDs.
	 *
	 * @return void
	 */
	private function _query(
		array $args,
		?array $callback
	): void {
		$query_args = wp_parse_args(
			[
				'cache_results'          => true,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'lazy_load_term_meta'    => false,
				'no_found_rows'          => true,
				'paged'                  => 1,
				// Used only in CLI context, higher value allowed.
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'posts_per_page'         => 500,
				'post_status'            => 'any',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'update_menu_item_cache' => false,
			],
			$args
		);

		$query = new WP_Query( $query_args );

		do {
			$this->_post_query->clear_cache();
			// posts are ids since we use ['fields' => 'ids']
			$posts = $this->_post_query->get_posts( $query->posts );

			$guternberg_post_ids = array_merge( ...array_map(
				static function ( WP_Post $post ) {
					if ( has_blocks( $post->post_content ) ) {
						$ids = Gutenberg::create_with_content( $post->post_content )
						                ->get_ids();

						return $ids;
					}

					return [];
				},
				$posts
			) );

			$gutenberg_posts = [];

			if ( count( $guternberg_post_ids ) ) {
				$gutenberg_posts = $this->_post_query->get_posts( $guternberg_post_ids );
			}

			$linked_posts = [];

			if ( ! is_null( $callback ) ) {
				$linked_posts = $callback( $posts, $this->_post_query );
			}

			$this->_insert_posts_to_keep( array_merge( $posts, $gutenberg_posts, $linked_posts ) );

			$query_args['paged'] ++;
			$query = new WP_Query( $query_args );
		} while ( $query->have_posts() );
	}

	/**
	 * @param WP_Post[] $posts
	 *
	 * @return void
	 */
	private function _insert_posts_to_keep( array $posts ): void {
		global $wpdb;

		if (empty($posts)) {
			return;
		}

		$placeholders = implode(
			', ',
			array_fill( 0, count( $posts ), '( %d, %s )' )
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . Init::TABLE_NAME . " (ID, post_type) VALUES $placeholders",
				array_merge(
					...array_map(
						static function ( WP_Post $post ) {
							return [ $post->ID, $post->post_type ];
						},
						$posts
					)
				)
			)
		);
	}
}
