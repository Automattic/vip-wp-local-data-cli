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
	 * @var Post_Query
	 */
	private Post_Query $_post_query;

	/**
	 * Clean_DB constructor.
	 */
	public function __construct() {
		$this->_post_query = new Post_Query();
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

			$this->_post_query->delete_posts_batch_by_ids( $ids );

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
			'SELECT posts.ID FROM `%1$s` as posts LEFT JOIN `%2$s` AS retained ON posts.ID = retained.ID WHERE retained.ID IS NULL AND posts.post_type != \'revision\' ORDER BY posts.ID ASC LIMIT %3$d,%4$d',
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
		$this->_post_query->clear_cache();
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

}
