<?php
/**
 * Retain recent `post` objects and their associated data.
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types = 1 );

namespace PMC\WP_Local_Data_CLI\Query_Args;

use PMC\WP_Local_Data_CLI\Query_Args;

/**
 * Class Post.
 */
// This class is a rare case where it's okay to be extended by other classes.
// phpcs:ignore SlevomatCodingStandard.Classes.RequireAbstractOrFinal.ClassNeitherAbstractNorFinal, Squiz.Commenting.ClassComment.Missing
class Post extends Query_Args {
	/**
	 * Build array of `WP_Query` arguments used to retrieve IDs to retain.
	 *
	 * @return array
	 */
	public static function get_query_args(): array {
		return [
			'post_type'  => 'post',
			'date_query' => [
				[
					'after' => '-3 months',
				],
			],
		];
	}

	public static bool $find_linked_ids = false;
}
