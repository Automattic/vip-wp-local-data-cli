<?php
/**
 * Perform database cleanup after querying for post IDs to retain.
 *
 * phpcs:disable Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
 * phpcs:disable WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * phpcs:disable WordPress.DB.PreparedSQL
 * phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types=1 );

namespace PMC\WP_Local_Data_CLI;

use WP_CLI;
use WP_Post;
use WPCOM_VIP_Cache_Manager;

/**
 * Class Clean_DB.
 */
final class Clean_DB {
	/**
	 * Clean_DB constructor.
	 */
	public function __construct() {
		$this->_delete_posts();
		$this->_clean_users_table();
		$this->_clean_usermeta_table();
		$this->_clean_comments_table();
		$this->_change_admin_email();
	}

	/**
	 * Loop through all posts and delete those that shouldn't be retained.
	 *
	 * @return void
	 */
	private function _delete_posts(): void {
		global $wpdb;

		WP_CLI::line( ' * Starting post deletion. This will take a while...' );

		$page     = 0;
		$per_page = 500;

		$total_ids     = $wpdb->get_var(
			"SELECT COUNT(ID) FROM `{$wpdb->posts}` WHERE post_type != 'revision'"
		);
		$total_to_keep = $wpdb->get_var(
			'SELECT COUNT(ID) FROM ' . Init::TABLE_NAME
		);
		$total_batches = ceil( ( $total_ids - $total_to_keep ) / $per_page );
		WP_CLI::line(
			sprintf(
				'   Expecting %1$s batches (%2$s total IDs; %3$s to keep; deleting %4$s per batch)',
				number_format_i18n( $total_batches ),
				number_format_i18n( $total_ids ),
				number_format_i18n( $total_to_keep ),
				number_format_i18n( $per_page )
			)
		);

		$this->_defer_counts( true );

		while (
		$ids = $wpdb->get_col( $this->_get_delete_query( $per_page ) )
		) {
			if ( $page > ( $total_batches * 1.25 ) ) {
				WP_CLI::warning(
					sprintf(
						'   > Infinite loop detected, terminating deletion with at least %1$s IDs left to delete!',
						number_format_i18n(
							count( $ids )
						)
					)
				);

				break;
			}

			WP_CLI::line(
				sprintf(
					'   > Processing batch %1$s (%2$d%%)',
					number_format_i18n( $page + 1 ),
					round(
						( $page + 1 ) / $total_batches * 100
					)
				)
			);

			$this->_delete_posts_batch_by_ids( $ids );

			$this->_free_resources();

			$page ++;
//          for debugging
//			if ($page > 9) {
//				break;
//			}
		}

		$this->_free_resources();
		$this->_defer_counts( false );

		WP_CLI::line( ' * Finished deleting posts.' );
	}

	/**
	 * Delete a post by ID.
	 *
	 * @param int $id_to_delete The ID of the post to delete.
	 *
	 * @return WP_Post|false The deleted post object on success, false on failure.
	 */
	private function _delete_post( $id_to_delete ) {
		$deleted = wp_delete_post( $id_to_delete, true );

		if ( ! $deleted instanceof WP_Post ) {
			WP_CLI::warning(
				sprintf(
					'     - Failed to delete post ID `%1$d`',
					$id_to_delete
				)
			);
		}

		return $deleted;
	}

	/**
	 * @param $post_rows
	 *
	 * @return WP_Post[]
	 */
	private function _get_posts_batch( $post_rows ) {
		return array_map(
			function ( $post_row ) {
				return get_post( $post_row );
			},
			$post_rows
		);
	}

	private function _delete_post_meta_batch( $posts, $meta_keys ) {
		global $wpdb;
		$post_ids = array_map( function ( $post ) {
			return $post->ID;
		}, $posts );

		if ( empty( $post_ids ) || empty( $meta_keys ) ) {
			return;
		}

		$post_placeholders     = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$meta_key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$query = "
		DELETE pm
		FROM {$wpdb->postmeta} AS pm
		WHERE pm.post_id IN ($post_placeholders)
		AND pm.meta_key IN ($meta_key_placeholders)
	";

		$args = array_merge( $post_ids, $meta_keys );
		$wpdb->query( $wpdb->prepare( $query, $args ) );
	}

	private function _delete_object_term_relationships_batch( $posts, $taxonomies ) {
		global $wpdb;
		$post_ids = array_map( function ( $post ) {
			return $post->ID;
		}, $posts );

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

	private function _get_object_taxonomies_batch( $posts ) {
		global $wpdb;
		$post_ids = array_map(
			fn( $post ) => $post->ID,
			$posts
		);
		if ( empty( $post_ids ) ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$query = $wpdb->prepare(
			"SELECT DISTINCT tt.taxonomy FROM {$wpdb->term_relationships} AS tr
     INNER JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
     WHERE tr.object_id IN ($placeholders)",
			...$post_ids
		);

		return $wpdb->get_col( $query );
	}

	private function _delete_metadata_batch( $posts ) {

	}

	private function _delete_comments_batch( $posts ) {

	}

	private function _delete_posts_batch_by_ids( $ids_to_delete ) {
		global $wpdb;

		$post_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$wpdb->posts}` WHERE ID IN (%s)",
			implode( ',', $ids_to_delete )
		) );

		if ( empty( $post_rows ) ) {
			return;
		}

//				wp_delete_post(1);

		$posts = $this->_get_posts_batch( $post_rows );

		return $this->_delete_posts_batch( $posts );
	}

	/**
	 * Delete a post by ID.
	 *
	 * @param WP_Post[] $id_to_delete The ID of the post to delete.
	 *
	 * @return WP_Post|false The deleted post object on success, false on failure.
	 */
	private function _delete_posts_batch( $posts ) {
		// based on wp_delete_post();
		global $wpdb;

		// we'll skip pre_delete_post
		// we also DON'T need to call wp_delete_attachment, as that method is mostly similar to post deletion

		$taxonomies      = $this->_get_object_taxonomies_batch( $posts );
		$base_taxonomies = [ 'category', 'post_tag' ];
		$this->_delete_object_term_relationships_batch( $posts, array_merge( $taxonomies, $base_taxonomies ) );

		$parent_to_children_posts_dict = $this->_get_parent_to_children_posts_dict_batch( $posts, [ 'attachment' ] );

		// TODO: Maybe delete children instead?
		$this->_reparent_children_to_parent_ancestors( $parent_to_children_posts_dict );

		// TODO: Collect IDs first, then delete in one go!
		$revisions = $this->_get_post_revisions_batch( $posts );

		$this->_delete_posts_batch( array_map( fn( $revision ) => $revision->ID, $revisions ) );

		$this->_delete_comments_batch( $posts );

		$this->_delete_post_meta_batch( $posts, [
			'_wp_trash_meta_status',
			'_wp_trash_meta_time'
		] );

		$delete_posts_placeholder = implode( ',', array_fill( 0, count( $posts ), '%d' ) );

		$delete_query = $wpdb->prepare( "DELETE FROM `{$wpdb->posts}` WHERE ID IN ($delete_posts_placeholder)", array_map( function ( $post ) {
			return $post->ID;
		}, $posts ) );

		$wpdb->query( $delete_query );
	}

	/**
	 * Prevent WP from performing certain counting operations.
	 *
	 * @param bool $defer To defer or not to defer, that is the question.
	 *
	 * @return void
	 */
	private function _defer_counts( bool $defer ): void {
		wp_defer_term_counting( $defer );
		wp_defer_comment_counting( $defer );
	}

	/**
	 * Build query to create list of IDs to check against list to retain.
	 *
	 * @param int $per_page IDs per page.
	 *
	 * @return string
	 */
	private function _get_delete_query( int $per_page ): string {
		global $wpdb;

		return $wpdb->prepare(
		// Intentionally using complex placeholders to prevent incorrect quoting of table names.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			'SELECT ID FROM `%1$s` WHERE ID NOT IN ( SELECT ID FROM `%2$s` ) AND post_type != \'revision\' ORDER BY ID ASC LIMIT %3$d,%4$d',
			$wpdb->posts,
			Init::TABLE_NAME,
			0,
			$per_page
		);
	}

	/**
	 * Perform operations to free resources.
	 *
	 * @return void
	 */
	private function _free_resources(): void {
		vip_reset_db_query_log();
		vip_reset_local_object_cache();
		WPCOM_VIP_Cache_Manager::instance()->clear_queued_purge_urls();
	}

	/**
	 * Remove sensitive data from the users table.
	 *
	 * @return void
	 */
	private function _clean_users_table(): void {
		global $wpdb;

		WP_CLI::line( " * Removing PII from {$wpdb->users}." );

		foreach (
			$wpdb->get_col( "SELECT ID FROM {$wpdb->users};" ) as $user_id
		) {
			$wpdb->update(
				$wpdb->users,
				[
					'user_email' => sprintf(
						'user-%1$d@%2$s',
						$user_id,
						LOCAL_DOMAIN
					),
				],
				[
					'ID' => $user_id,
				],
				[
					'user_email' => '%s',
				],
				[
					'ID' => '%d',
				]
			);
		}
	}

	/**
	 * Remove sensitive data from the usermeta table.
	 *
	 * @return void
	 */
	private function _clean_usermeta_table(): void {
		global $wpdb;

		WP_CLI::line( " * Removing PII from {$wpdb->usermeta}." );

		// Session tokens include users' IP address.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s;",
				'session_tokens'
			)
		);
	}

	/**
	 * Remove sensitive data from the comments table.
	 *
	 * @return void
	 */
	private function _clean_comments_table(): void {
		global $wpdb;

		WP_CLI::line( " * Removing PII from {$wpdb->comments}." );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->comments} SET comment_author_email=%s, comment_author_IP='', comment_agent='';",
				'commenter@' . LOCAL_DOMAIN
			)
		);
	}

	/**
	 * Overwrite admin email used for certain notifications.
	 *
	 * @return void
	 */
	private function _change_admin_email(): void {
		WP_CLI::line( ' * Overwriting `admin_email` option.' );

		update_option(
			'admin_email',
			'admin@' . LOCAL_DOMAIN
		);
		delete_option( 'new_admin_email' );
	}

	private function _get_parent_to_children_posts_dict_batch( array $posts, $additional_post_types ) {
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

	private function _reparent_children_to_parent_ancestors( array $parent_to_children_posts_dict ) {
		global $wpdb;
		$parent_post_ids              = array_keys( $parent_to_children_posts_dict );
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

	private function _get_post_revisions_batch( array $posts ) {
		global $wpdb;
		$post_ids = array_map( function ( $post ) {
			return $post->ID;
		}, $posts );

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
}
