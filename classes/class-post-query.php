<?php

declare( strict_types=1 );

namespace PMC\WP_Local_Data_CLI;

// TODO: Change to a singleton :)

final class Post_Query {


	/**
	 * @param $post_rows
	 *
	 * @return WP_Post[]
	 */
	public static function get_posts_batch( $post_rows ): array {
		return array_map(
			function ( $post_row ) {
				return get_post( $post_row );
			},
			$post_rows
		);
	}

	private static function _delete_post_meta_batch( $post_ids ): void {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return;
		}

		$post_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$query = "
		DELETE pm
		FROM {$wpdb->postmeta} AS pm
		WHERE pm.post_id IN ($post_placeholders)
	";

		$wpdb->query( $wpdb->prepare( $query, $post_ids ) );
	}

	private static function _delete_object_term_relationships_batch( $post_ids, $taxonomies ): void {
		global $wpdb;

		if ( empty( $post_ids ) || empty( $taxonomies ) ) {
			return;
		}

		$post_placeholders     = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$taxonomy_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		$query = "
        DELETE tr
        FROM {$wpdb->term_relationships} AS tr
        INNER JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        WHERE tr.object_id IN ($post_placeholders)
        AND tt.taxonomy IN ($taxonomy_placeholders)
    ";

		$args = array_merge( $post_ids, $taxonomies );
		$wpdb->query( $wpdb->prepare( $query, ...$args ) );
	}

	private static function _get_object_taxonomies_batch( $post_ids ): array {
		global $wpdb;
		if ( empty( $post_ids ) ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$query = $wpdb->prepare(
			"SELECT DISTINCT tt.taxonomy FROM {$wpdb->term_relationships} AS tr
     INNER JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
     WHERE tr.object_id IN ($placeholders)",
			$post_ids
		);

		return $wpdb->get_col( $query );
	}

	private static function _delete_comments_meta_batch( $comment_ids ): void {
		global $wpdb;

		if ( empty( $comment_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );

		$query = $wpdb->prepare(
			"DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($placeholders)",
			$comment_ids
		);

		$wpdb->query( $query );
	}

	private static function _delete_comments_batch( $post_ids ): void {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$comment_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders)",
			$post_ids
		) );

		Post_Query::_delete_comments_meta_batch( $comment_ids );

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$query = $wpdb->prepare(
			"DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders)",
			$post_ids
		);

		$wpdb->query( $query );
	}

	public static function delete_posts_batch( $posts, \PMC\WP_Local_Data_CLI\Clean_DB $instance ): void {
		// based on wp_delete_post();
		global $wpdb;

		if ( empty( $posts ) ) {
			return;
		}

		// we'll skip pre_delete_post
		// we also DON'T need to call wp_delete_attachment, as that method is mostly similar to post deletion

		$post_ids = array_map(
			fn( $post ) => $post->ID,
			$posts
		);

		$taxonomies      = Post_Query::_get_object_taxonomies_batch( $post_ids );
		$base_taxonomies = [ 'category', 'post_tag' ];
		Post_Query::_delete_object_term_relationships_batch( $post_ids, array_merge( $taxonomies, $base_taxonomies ) );

		$parent_to_children_posts_dict = self::_get_parent_to_children_posts_dict_batch( $posts );

		// TODO: Maybe delete children instead?
		self::_reparent_children_to_parent_ancestors( $parent_to_children_posts_dict );

		// TODO: Collect IDs first, then delete in one go!
		$revisions = self::_get_post_revisions_batch( $post_ids );

		Post_Query::delete_posts_batch( $revisions, $instance );

		Post_Query::_delete_comments_batch( $post_ids );

		Post_Query::_delete_post_meta_batch( $post_ids );

		$delete_posts_placeholder = implode( ',', array_fill( 0, count( $posts ), '%d' ) );

		$delete_query = $wpdb->prepare( "DELETE FROM `{$wpdb->posts}` WHERE ID IN ($delete_posts_placeholder)", array_map( function ( $post ) {
			return $post->ID;
		}, $posts ) );

		$wpdb->query( $delete_query );

	}

	private static function _get_parent_to_children_posts_dict_batch( array $posts ): array {
		$additional_post_types = [ 'attachment' ];
		global $wpdb;
		$post_ids = array_map( function ( $post ) {
			return $post->ID;
		}, $posts );

		if ( empty( $post_ids ) ) {
			return [];
		}

		$post_types = array_unique( array_merge( array_map( function ( $post ) {
			return $post->post_type;
		}, $posts ), $additional_post_types ) );

		$placeholders                       = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$additional_post_types_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$query = $wpdb->prepare(
			"SELECT post_parent, ID FROM {$wpdb->posts} WHERE post_parent IN ($placeholders) AND post_type IN ($additional_post_types_placeholders)",
			array_merge( $post_ids, $post_types )
		);

		$posts                   = $wpdb->get_results( $query );
		$parent_post_id_to_posts = [];
		foreach ( $posts as $post ) {
			$parent_post_id_to_posts[ $post->post_parent ][] = $post;
		}

		return $parent_post_id_to_posts;
	}

	private static function _reparent_children_to_parent_ancestors( array $parent_to_children_posts_dict ): void {
		global $wpdb;
		$parent_post_ids = array_keys( $parent_to_children_posts_dict );

		if ( empty( $parent_post_ids ) ) {
			return;
		}

		$parent_post_ids_placeholders = implode( ',', array_fill( 0, count( $parent_post_ids ), '%d' ) );
		$parent_posts                 = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE ID IN ($parent_post_ids_placeholders)",
				$parent_post_ids )
		);

		$parent_posts_dict = [];

		foreach ( $parent_posts as $parent_post ) {
			if ( $parent_posts_dict[ $parent_post->ID ] ) {
				continue;
			}
			$parent_posts_dict[ $parent_post->ID ] = get_post( $parent_post );
		}

		$grand_parent_post_ids = array_map( function ( $post ) {
			return $post->post_parent;
		}, $parent_posts );

		if ( empty( $grand_parent_post_ids ) ) {
			return;
		}

		$grand_parent_post_ids_placeholders = implode( ',', array_fill( 0, count( $grand_parent_post_ids ), '%d' ) );

		$grand_parent_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE ID IN ($grand_parent_post_ids_placeholders)",
				$grand_parent_post_ids_placeholders
			)
		);

		$grand_parent_posts_dict = [];

		foreach ( $grand_parent_posts as $grand_parent_post ) {
			if ( $grand_parent_posts_dict[ $grand_parent_post->ID ] ) {
				continue;
			}
			$grand_parent_posts_dict[ $grand_parent_post->ID ] = get_post( $grand_parent_post );
		}

		foreach ( $parent_to_children_posts_dict as $parent_post_id => $children_posts ) {
			$parent_post = $parent_posts_dict[ $parent_post_id ];

			if ( empty( $parent_post ) ) {
				continue;
			}

			$grand_parent_post = $grand_parent_posts_dict[ $parent_post->post_parent ];

			if ( empty( $grand_parent_post ) ) {
				continue;
			}

			$child_ids = array_map(
				function ( $child_post ) {
					return $child_post->ID;
				},
				$children_posts
			);
			if ( ! empty( $child_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );
				$query        = $wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_parent = %d WHERE ID IN ($placeholders)",
					array_merge( [ $grand_parent_post->ID ], $child_ids )
				);
				$wpdb->query( $query );
			}
		}
	}

	private static function _get_post_revisions_batch( array $post_ids ): array {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$query = $wpdb->prepare(
			"SELECT * FROM {$wpdb->posts} WHERE post_parent IN ($placeholders) AND post_type = %s",
			array_merge( $post_ids, [ 'revision' ] ) );

		return array_map(
			function ( $post_row ) {
				return get_post( $post_row );
			},
			$wpdb->get_results( $query )
		);
	}

	public static function delete_posts_batch_by_ids( $ids_to_delete, \PMC\WP_Local_Data_CLI\Clean_DB $instance ): void {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids_to_delete ), '%d' ) );

		$post_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$wpdb->posts}` WHERE ID IN ($placeholders)",
			$ids_to_delete
		) );

		if ( empty( $post_rows ) ) {
			return;
		}

		$posts = self::get_posts_batch( $post_rows );

		self::delete_posts_batch( $posts, $instance );
	}
}
