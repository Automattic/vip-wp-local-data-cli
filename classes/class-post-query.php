<?php

declare( strict_types=1 );

namespace PMC\WP_Local_Data_CLI;

use Exception;
use WP_CLI;
use WP_Post;

final class Post_Query {

	/**
	 * @var WP_Post[]
	 */
	private array $_post_cache = [];


	/**
	 * @param $post_rows
	 *
	 * @return WP_Post[]
	 */
	private function _get_posts_by_rows( $post_rows ): array {
		return array_map(
			function ( $post_row ) {
				return $this->_get_post_by_row($post_row);
			},
			$post_rows
		);
	}

	/**
	 * @return void
	 */
	public function clear_cache(): void {
		$this->_post_cache = [];
	}

	/**
	 * @param $id
	 *
	 * @return WP_Post|null
	 */
	private function _get_cached_post($id): ?WP_Post {
		if (isset($this->_post_cache[$id])) {
			return $this->_post_cache[$id];
		}

		return null;
	}

	/**
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	private function _set_cached_post(WP_Post $post): void {
		$this->_post_cache[$post->ID] = $post;
	}

	private function _get_post_by_row( $post_row ): ?WP_Post {
		$cached = $this->_get_cached_post($post_row->ID);
		if (isset($cached)) {
			return $cached;
		}
		$post = get_post($post_row);
		$this->_set_cached_post($post);

		return $post;
	}

	/**
	 * @param $id
	 * @param bool $suppress_warning
	 *
	 * @return WP_Post|null
	 */
	private function _get_post_by_id( $id, bool $suppress_warning = false): WP_Post|null {
		$cached = $this->_get_cached_post($id);
		if (isset($cached)) {
			return $cached;
		}

		// uh oh, this incurs a select query!
		$post = get_post($id);
		$this->_set_cached_post($post);

		if (!$suppress_warning) {
			// TODO: Custom exception classes
			WP_CLI::warning(new Exception("Performance issue detected, post $id was not cached and was queried from the db."));
		}

		return $post;
	}

	private function _delete_post_meta_batch( $post_ids ): void {
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

	private function _delete_object_term_relationships_batch( $post_ids, $taxonomies ): void {
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

	private function _get_object_taxonomies_batch( $post_ids ): array {
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

	private function _delete_comments_meta_batch( $comment_ids ): void {
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

	private function _delete_comments_batch( $post_ids ): void {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$comment_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders)",
			$post_ids
		) );

		$this->_delete_comments_meta_batch( $comment_ids );

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$query = $wpdb->prepare(
			"DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders)",
			$post_ids
		);

		$wpdb->query( $query );
	}

	public function delete_posts_batch( $posts ): void {
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

		$taxonomies      = $this->_get_object_taxonomies_batch( $post_ids );
		$base_taxonomies = [ 'category', 'post_tag' ];
		$this->_delete_object_term_relationships_batch( $post_ids, array_merge( $taxonomies, $base_taxonomies ) );

		$parent_to_children_posts_dict = $this->_get_parent_to_children_posts_dict_batch( $posts );

		// TODO: Maybe delete children instead?
		$this->_reparent_children_to_parent_ancestors( $parent_to_children_posts_dict );

		$revisions = $this->_get_post_revisions_batch( $post_ids );

		// TODO: Collect IDs first instead of recursing, then delete in one go!
		// recursion
		$this->delete_posts_batch( $revisions );

		$this->_delete_comments_batch( $post_ids );

		$this->_delete_post_meta_batch( $post_ids );

		$delete_posts_placeholder = implode( ',', array_fill( 0, count( $posts ), '%d' ) );

		// TODO: Should we remove deleted posts from the cache?
		$delete_query = $wpdb->prepare( "DELETE FROM `{$wpdb->posts}` WHERE ID IN ($delete_posts_placeholder)", array_map( function ( $post ) {
			return $post->ID;
		}, $posts ) );

		$wpdb->query( $delete_query );
	}

	private function _get_parent_to_children_posts_dict_batch( array $posts ): array {
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

		$parent_id_placeholders                       = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$additional_post_types_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// TODO: Can this be cached?
		$query = $wpdb->prepare(
			"SELECT * FROM {$wpdb->posts} WHERE post_parent IN ($parent_id_placeholders) AND post_type IN ($additional_post_types_placeholders)",
			array_merge( $post_ids, $post_types )
		);

		$children_post_rows                   = $wpdb->get_results( $query );
		$parent_post_id_to_posts = [];
		foreach ( $children_post_rows as $row ) {
			$child_post = $this->_get_post_by_row($row);
			$parent_post_id_to_posts[ $child_post->post_parent ][] = $child_post;
		}

		return $parent_post_id_to_posts;
	}


	/**
	 * @param $post_ids
	 *
	 * @return WP_Post[]
	 */
	private function _build_post_dictionary($post_ids): array {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return [];
		}

		$dict = [];

		$uncached_post_ids = array_filter($post_ids, function ($id) use (&$dict) {
			$cached = $this->_get_cached_post($id);
			if ($cached) {
				$dict[ $id ] = $cached;
				return false;
			}
			return $this->_get_cached_post($id) === null;
		});

		if (empty($uncached_post_ids)) {
			return $dict;
		}

		$placeholders = implode( ',', array_fill( 0, count( $uncached_post_ids ), '%d' ) );

		$query = $wpdb->prepare(
			"SELECT * FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
			$uncached_post_ids
		);

		return array_reduce(
			$wpdb->get_results( $query ),
			function ( $carry, $post_row ) {
				$carry[ $post_row->ID ] = $this->_get_post_by_row($post_row);
				return $carry;
			},
			$dict
		);
	}

	private function _reparent_children_to_parent_ancestors( array $parent_to_children_posts_dict ): void {
		global $wpdb;
		$parent_post_ids = array_keys( $parent_to_children_posts_dict );

		$parent_posts_dict = $this->_build_post_dictionary($parent_post_ids);

		$grand_parent_post_ids = array_map( function ( $post ) {
			return $post->post_parent;
		}, array_values($parent_posts_dict) );

		if ( empty( $grand_parent_post_ids ) ) {
			// abort, can't reparent without any grandparents.
			return;
		}

		$grand_parent_post_dictionary = $this->_build_post_dictionary($grand_parent_post_ids);

		foreach ( $parent_to_children_posts_dict as $parent_post_id => $children_posts ) {
			$parent_post = $parent_posts_dict[ $parent_post_id ];

			if ( empty( $parent_post ) ) {
				continue;
			}


			// TODO: If there are no grandparents, do we reparent or we skip? Check WP code later
			$grand_parent_post = $grand_parent_post_dictionary[ $parent_post->post_parent ] ?? null;;

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
				foreach ( $child_ids as $child_id ) {
					$child_post = $this->_get_post_by_id($child_id);
					$child_post->post_parent = $grand_parent_post->ID;
				}
			}
		}
	}

	private function _get_post_revisions_batch( array $post_ids ): array {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// TODO: Can this be cached?
		$query = $wpdb->prepare(
			"SELECT * FROM {$wpdb->posts} WHERE post_parent IN ($placeholders) AND post_type = %s",
			array_merge( $post_ids, [ 'revision' ] ) );

		return $this->_get_posts_by_rows($wpdb->get_results( $query ));
	}

	/**
	 * @param array $posts
	 * @param $meta_key
	 *
	 * @return (string|null)[] Post ID to its meta key value
	 */
	public function get_posts_meta( array $posts, $meta_key): array {
		$ids = array_map(
			function ( $post ) {
				return $post->ID;
			},
			$posts
		);

		if ( empty( $ids ) ) {
			return [];
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$query = $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key = %s",
			array_merge( $ids, [ $meta_key ] )
		);

		return array_reduce(
			$wpdb->get_results( $query ),
			function ( $carry, $row ) {
				$carry[ $row->post_id ] = maybe_unserialize($row->meta_value);
				return $carry;
			},
			[]
		);
	}

	/**
	 * @param $ids
	 *
	 * @return WP_Post[]
	 */
	public function get_posts($ids): array {
		global $wpdb;

		$cached_posts = [];

		$uncached_post_ids = array_filter($ids, function ($id) use (&$cached_posts) {
			$cached = $this->_get_cached_post($id);
			if (isset($cached)) {
				$cached_posts[] = $cached;
				return false;
			}
			return true;
		});

		if (empty($uncached_post_ids)) {
			return $cached_posts;
		}

		$placeholders = implode( ',', array_fill( 0, count( $uncached_post_ids ), '%d' ) );

		$uncached_post_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$wpdb->posts}` WHERE ID IN ($placeholders)",
			$uncached_post_ids
		) );

		return array_merge($cached_posts, $this->_get_posts_by_rows( $uncached_post_rows ));
	}

	public function delete_posts_batch_by_ids( $ids_to_delete ): void {
		global $wpdb;

		$cached_posts = [];

		$uncached_post_ids = array_filter($ids_to_delete, function ($id) use (&$cached_posts) {
			$cached_post = $this->_get_cached_post($id);
			if (isset($cached_post)) {
				$cached_posts[] = $cached_post;
				return false;
			}
			return true;
		});

		$placeholders = implode( ',', array_fill( 0, count( $uncached_post_ids ), '%d' ) );

		if ( empty( $uncached_post_ids ) ) {
			$this->delete_posts_batch($cached_posts);
			return;
		}

		$uncached_post_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$wpdb->posts}` WHERE ID IN ($placeholders)",
			$uncached_post_ids
		) );

		$uncached_posts = $this->_get_posts_by_rows( $uncached_post_rows );

		$this->delete_posts_batch( array_merge($cached_posts, $uncached_posts) );
	}
}
