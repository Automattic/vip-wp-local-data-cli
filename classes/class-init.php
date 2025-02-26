<?php
/**
 * Commands to prepare a production database backup for use in local development
 * environments.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * phpcs:disable WordPress.DB.PreparedSQL
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types = 1 );

namespace PMC\WP_Local_Data_CLI;

use Throwable;
use WP_CLI;

use function WP_CLI\Utils\get_flag_value;

/**
 * Class Init.
 */
final class Init {
	/**
	 * WP-CLI command slug.
	 */
	public const COMMAND_NAME = 'pmc-local-data';

	/**
	 * Name of table used to hold post IDs to retain.
	 */
	public const TABLE_NAME = 'pmc_local_data_posts_to_keep';

	/**
	 * Prepare production database for use in local-development environment.
	 *
	 *  ## OPTIONS
	 *
	 *  [--debug-mode]
	 *  : Skip deletion step and retain table holding found IDs.
	 *
	 * ## EXAMPLES
	 *     wp pmc-local-data start
	 *     wp pmc-local-data start --debug-mode
	 *
	 * @subcommand start
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function start( array $args, array $assoc_args ): void {
		try {
			$debug_mode = get_flag_value(
				$assoc_args,
				'debug-mode',
				false
			);

			WP_CLI::line( 'Starting local-data process.' );

			if ( $debug_mode ) {
				WP_CLI::warning(
					'Debug mode active: `Clean_DB` class will be skipped and IDs table will be retained.'
				);
			}

			do_action( 'pmc_wp_cli_local_data_before_processing' );

			$this->_drop_table();
			$this->_create_table();

			$this->_query_for_ids_to_keep();

			if ( $debug_mode ) {
				WP_CLI::warning( 'Debug mode active: skipping `Clean_DB` class.' );
			} else {
				new Clean_DB();
			}

			do_action( 'pmc_wp_cli_local_data_after_processing' );

			if ( $debug_mode ) {
				WP_CLI::warning(
					sprintf(
						'Debug mode active: retaining `%1$s` table.',
						self::TABLE_NAME
					)
				);
			} else {
				$this->_drop_table();
			}

			WP_CLI::line( 'Process complete.' );
		} catch ( Throwable $throwable ) {
			$this->_handle_error( $throwable );
		}
	}

	/**
	 * Create custom table to hold post IDs to retain.
	 *
	 * @return void
	 */
	private function _create_table(): void {
		global $wpdb;

		$wpdb->query(
			'CREATE TABLE IF NOT EXISTS ' . self::TABLE_NAME
			. " (
				ID bigint(20) unsigned NOT NULL,
				post_type varchar(20) NOT NULL,
				PRIMARY KEY  (ID),
				KEY type_id (post_type,ID)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * Remove custom table holding post IDs to retain.
	 *
	 * @return void
	 */
	private function _drop_table(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::TABLE_NAME );
	}

	/**
	 * Retrieve number of post-object IDs identified for retention.
	 *
	 * @return int
	 */
	private function _count_ids_to_keep(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(ID) FROM ' . self::TABLE_NAME
		);
	}

	/**
	 * Run all configured queries to build list of post-object IDs to retain.
	 *
	 * @return void
	 */
	private function _query_for_ids_to_keep(): void {
		$query_args_instances = $this->_get_query_args_instances();

		/**
		 * Each `Query_args` instance is processed within a try-catch block
		 * because some of our plugins don't support the autoloader in
		 * `pmc-global-functions`, and if a theme doesn't activate one of the
		 * plugins that a `Query_Args` class depends on, a fatal error may be
		 * thrown.
		 *
		 * We can safely assume that if the active theme doesn't load an
		 * affected plugin, its data is not required for local development.
		 * Rather than stop the cleanup process, the try-catch allows us to
		 * surface the error and carry on.
		 */
		foreach ( $query_args_instances as $query_args_instance ) {
			try {
				new Query( $query_args_instance );
			} catch ( Throwable $throwable ) {
				WP_CLI::warning( $throwable->getMessage() );
			}
		}

		/**
		 * Backfill is processed after all initial IDs are gathered in case
		 * linked IDs themselves have dependencies.
		 *
		 * This process may run multiple times because a run of backfill could
		 * add additional IDs that themselves have dependencies.
		 */
		do {
			$found_ids = $this->_count_ids_to_keep();

			foreach ( $query_args_instances as $query_args_instance ) {
				if ( $query_args_instance::$skip_backfill ) {
					continue;
				}

				try {
					new Query( $query_args_instance, true );
				} catch ( Throwable $throwable ) {
					WP_CLI::warning( $throwable->getMessage() );
				}
			}
		} while ( $found_ids !== $this->_count_ids_to_keep() );

		if ( $found_ids < 1 ) {
			WP_CLI::error( 'No IDs were found. Please check the logs.' );
		}

		WP_CLI::line(
			sprintf(
				'   > Will retain %1$s IDs.',
				number_format_i18n( $found_ids, 0 )
			)
		);
	}

	/**
	 * Provide query arguments and optional callbacks used to identify post IDs
	 * to retain.
	 *
	 * @return array
	 */
	private function _get_query_args_instances(): array {
		$query_args = [
			new Query_Args\bbPress(),
			new Query_Args\BuddyPress_Email(),
			new Query_Args\CoAuthors_Plus(),
			new Query_Args\Nav_Menu_Item(),
			new Query_Args\OEmbed_Cache(),
			new Query_Args\Page(),
			new Query_Args\Post(),
			new Query_Args\Safe_Redirect_Manager(),
			new Query_Args\WPCOM_Legacy_Redirector(),
		];

		/**
		 * Allow themes to add support for custom features that are not part of
		 * `pmc-plugins`.
		 *
		 * @param array $instances Instantiated classes that extend the
		 *                         `Query_Args` base class.
		 */
		$additional_instances = apply_filters(
			'pmc_wp_cli_local_data_query_args_instances',
			[]
		);

		return array_filter(
			array_merge(
				$query_args,
				$additional_instances
			),
			static fn ( mixed $instance ): bool =>
				$instance instanceof Query_Args
		);
	}

	/**
	 * Handle errors during trimming process.
	 *
	 * @param Throwable $throwable Error details.
	 * @return void
	 */
	private function _handle_error( Throwable $throwable ): void {
		global $wpdb;

		$wpdb->query( 'DROP DATABASE ' . DB_NAME );

		WP_CLI::error( $throwable->getMessage() );
	}
}
