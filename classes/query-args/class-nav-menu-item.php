<?php
/**
 * Retain `nav_menu_item` objects.
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types = 1 );

namespace PMC\WP_Local_Data_CLI\Query_Args;

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
	 * Process dependent data associated with a given ID, such as a post's
	 * thumbnail.
	 *
	 * If post type has no dependent data, set `static::$find_linked_ids` to
	 * false to skip this method.
	 *
	 * @param int    $id Post ID.
	 * @param string $post_type Post type of given ID.
	 * @return array
	 */
	// Declaration must be compatible with overridden method.
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed, Squiz.Commenting.FunctionComment.Missing, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
//	public static function get_linked_ids( int $id, string $post_type ): array {
//		$ids = [];
//
//		$menu_item_type = get_post_meta( $id, '_menu_item_type', true );
//
//		if ( 'post_type' !== $menu_item_type ) {
//			return $ids;
//		}
//
//		$ids[] = [
//			'ID'        => (int) get_post_meta(
//				$id,
//				'_menu_item_object_id',
//				true
//			),
//			'post_type' => get_post_type( $id ),
//		];
//
//		return $ids;
//	}
}
