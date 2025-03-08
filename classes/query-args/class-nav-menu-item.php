<?php
/**
 * Retain `nav_menu_item` objects.
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types = 1 );

namespace PMC\WP_Local_Data_CLI\Query_Args;

use PMC\WP_Local_Data_CLI\Post_Query;
use PMC\WP_Local_Data_CLI\Query_Args;

/**
 * Class Nav_Menu_Item.
 */
final class Nav_Menu_Item extends Query_Args {
	/**
	 * Build array of `WP_Query` arguments used to retrieve IDs to retain.
	 *
	 * @return array
	 */
	public static function get_query_args(): array {
		return [
			'post_type' => 'nav_menu_item',
		];
	}

	/**
	 * @param \WP_Post[] $posts
	 * @param Post_Query $post_query
	 *
	 * @return array|\WP_Post[]
	 */
	public static function get_linked_ids( $posts, $post_query ): array {

		$post_id_to_menu_item_type = $post_query->get_posts_meta($posts, '_menu_item_type');

		$ids_to_process = array_filter(
			$post_id_to_menu_item_type,
			static function ( $menu_item_type ) {
				return 'post_type' === $menu_item_type;
			}
		);

		$posts_to_process = $post_query->get_posts( $ids_to_process );

		$post_id_to_menu_item_object_id = $post_query->get_posts_meta($posts_to_process, '_menu_item_object_id');

		$menu_item_object_ids_to_process = array_filter(
			$post_id_to_menu_item_object_id,
			static function ( $menu_item_object_id ) {
				return ! empty( $menu_item_object_id );
			}
		);

		// yep they're posts.
		return $post_query->get_posts( $menu_item_object_ids_to_process );
	}
}
