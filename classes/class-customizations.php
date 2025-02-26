<?php
/**
 * Augment data cleanup with PMC-specific behaviours.
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types = 1 );

namespace PMC\WP_Local_Data_CLI;

use WP_CLI;
use WP_User;

/**
 * Class Customizations.
 */
final class Customizations {


	public static function get_instance(): self {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Customizations constructor.
	 */
	private function __construct() {
		$this->_setup_hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	private function _setup_hooks(): void {
		add_action(
			'pmc_wp_cli_local_data_before_processing',
			static function (): void {
				add_filter( 'pre_wp_mail', '__return_false' );
			},
			PHP_INT_MIN
		);
		add_action(
			'pmc_wp_cli_local_data_after_processing',
			static function (): void {
				remove_filter( 'pre_wp_mail', '__return_false' );
			},
			PHP_INT_MAX
		);

		add_action(
			'pmc_wp_cli_local_data_before_processing',
			[ $this, 'remove_sensitive_data' ]
		);

		add_action(
			'pmc_wp_cli_local_data_before_processing',
			[ $this, 'disconnect_jetpack' ]
		);

		// Run late as requests from Cron Control can interfere.
		add_action(
			'pmc_wp_cli_local_data_after_processing',
			[ $this, 'remove_superfluous_vip_tables' ],
			999
		);

		add_action(
			'pmc_wp_cli_local_data_after_processing',
			[ $this, 'add_dev_user' ]
		);

		add_action(
			'pmc_wp_cli_local_data_after_processing',
			[ $this, 'rebuild_sitemaps' ]
		);

		add_action(
			'pmc_wp_cli_local_data_after_processing',
			[ $this, 'flush_rewrites' ]
		);

		add_action(
			'pmc_wp_cli_local_data_after_processing',
			[ $this, 'remove_google_analytics_ids' ]
		);
	}

	/**
	 * Run sensitive-data cleanup that should be performed before all other
	 * database manipulations.
	 *
	 * @return void
	 */
	public function remove_sensitive_data(): void {
		WP_CLI::line(
			' * Removing sensitive data, such as API keys, before trimming database.'
		);

//		VIP_Go_Sync_Cleanup::get_instance()->do_cleanup();

		/**
		 * The JWT secret is not handled by the `VIP_Go_Sync_Cleanup` because
		 * the value must be available to all non-production environments.
		 */
//		delete_option( JWT::OPTION_NAME_SECRET );
	}

	/**
	 * Remove Jetpack connection data.
	 *
	 * @return void
	 */
	public function disconnect_jetpack(): void {
		WP_CLI::line( ' * Disconnecting Jetpack.' );

		foreach (
			[
				'jetpack_active_plan',
				'jetpack_options',
				'jetpack_private_options',
			] as $option
		) {
			delete_option( $option );
		}
	}

	/**
	 * Remove unnecessary tables created by VIP features that are not active
	 * locally.
	 *
	 * @return void
	 */
	public function remove_superfluous_vip_tables(): void {
		global $wpdb;

		WP_CLI::line( ' * Removing certain tables created by VIP features.' );

		foreach (
			[
				'wp_a8c_cron_control_jobs',
				'wp_jetpack_sync_queue',
				'wp_vip_search_index_queue',
			] as $table
		) {
			// Direct queries are necessary as WPDB does not provide a method to drop tables. Table names cannot be interpolated.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Add our default local user.
	 *
	 * @return void
	 */
	public function add_dev_user(): void {
		WP_CLI::line( ' * Adding `pmcdev` user.' );

		$id = wp_insert_user(
			[
				'user_login' => 'pmcdev',
				'user_pass'  => 'pmcdev',
				'user_email' => 'pmcdev@' . LOCAL_DOMAIN,
				'role'       => 'administrator',
			]
		);

		$user = new WP_User( $id );
		$user->add_cap( 'view_query_monitor' );
	}

	/**
	 * Rebuild sitemaps to contain only post objects present in trimmed data.
	 *
	 * @return void
	 */
	public function rebuild_sitemaps(): void {
		// TODO: implement. Not as simple as it seems.
		WP_CLI::line(
			' * Sitemap regeneration has not been implemented. Refer to plugin\'s WP-CLI commands.'
		);
	}

	/**
	 * Flush rewrite rules so that option is populated.
	 *
	 * @return void
	 */
	public function flush_rewrites(): void {
		WP_CLI::line( ' * Flushing rewrite rules.' );

		wp_cache_delete( 'rewrite_rules', 'options' );
		// Used in CLI context.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
		flush_rewrite_rules( false );
		do_action( 'rri_flush_rules' );
	}

	/**
	 * Clear options holding Google Analytics IDs.
	 *
	 * @return void
	 */
	public function remove_google_analytics_ids(): void {
		WP_CLI::line( ' * Removing Google Analytics IDs.' );

		foreach (
			[
				'pmc_ga4_admin_tracking_id',
				'pmc_ga4_newsbreak_tracking_id',
				'pmc_google_analytics_account',
				'pmc_google_analytics_account_ga4',
				'pmc_google_tag_manager_account',
			] as $option
		) {
			delete_option( $option );
		}
	}
}
