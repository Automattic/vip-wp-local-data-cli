<?php
/**
 * Extract IDs from Gutenberg block attributes.
 *
 * @package pmc-wp-local-data-cli
 */

declare( strict_types = 1 );

namespace PMC\WP_Local_Data_CLI;

use WP_Post;

/**
 * Class Gutenberg.
 */
final class Gutenberg {
	/**
	 * Specify blocks and relevant attributes that contain dependent post-object
	 * IDs.
	 *
	 * @var array
	 */
	private static array $_block_map = [
		'core/cover'                                      => [
			'id',
		],
		'core/file'                                       => [
			'id',
		],
		'core/gallery'                                    => [
			'ids',
		],
		'core/image'                                      => [
			'id',
		],
		'core/media-text'                                 => [
			'mediaId',
		],
		'core/navigation-link'                            => [
			'id',
		],
		'core/navigation-submenu'                         => [
			'id',
		],
		'core/video'                                      => [
			'id',
		],
		'pmc/additional-related-content'                  => [
			'relatedLinks.data[].id',
		],
		'pmc/buy-now'                                     => [
			'image',
		],
		'pmc/buy-now-button'                              => [
			'image',
		],
		'pmc/content-recirculation'                       => [
			'selectedPosts[].id',
		],
		'pmc/featured-article-inline-gallery'             => [
			'ids',
		],
		'pmc/fullscreen-cover'                            => [
			'imageId',
			'items[].postId',
		],
		'pmc/linked-gallery'                              => [
			'galleryId',
			'galleryImages[]',
		],
		'pmc/list-item'                                   => [
			'id',
		],
		'pmc/pilot-item'                                  => [
			'related[]',
		],
		'pmc/product-card'                                => [
			'productData[].image',
		],
		'pmc/product-grid-item'                           => [
			'image',
		],
		'pmc/profile'                                     => [
			'profileImageId',
			// 'profileButtonUrl',
		],
		'pmc/story'                                       => [
			'featuredImageID',
			'postID',
		],
		'pmc/story-digital-daily'                         => [
			'featuredImageID',
			'postID',
		],
		'pmc/story-digital-daily-special-edition-article' => [
			'featuredImageID',
			'postID',
		],
		'pmc/story-digital-daily-special-edition-cover'   => [
			'featuredImageID',
			'postID',
		],
		'pmc/story-gallery'                               => [
			'featuredImageID',
			'postID',
		],
		'pmc/story-influencers'                           => [
			'featuredImageID',
			'postID',
		],
		'pmc/story-runway-review'                         => [
			'featuredImageID',
			'postID',
		],
		'pmc/story-top-video'                             => [
			'featuredImageID',
			'postID',
		],
		'pmc/story-video'                                 => [
			'featuredImageID',
			'postID',
		],
		'pmc/stylized-review'                             => [
			'productData[].image',
		],
	];

	/**
	 * Post-object IDs extracted from block attributes.
	 *
	 * @var array
	 */
	private array $_ids = [];

	/**
	 * Gutenberg constructor.
	 */
	public function __construct() {
	}

	public static function create_with_content($post_content) {
		$instance = new self();
		$instance->_gather_ids_with_post_content($post_content);

		return $instance;
	}

	/**
	 * Retrieve IDs gathered from block attributes.
	 *
	 * @return array
	 */
	public function get_ids(): array {
		$ids_to_insert = [];

		$ids = array_filter(
			array_unique(
				array_merge( ...$this->_ids )
			)
		);

		return $ids;
	}

	/**
	 * Parse blocks from content and process result.
	 *
	 * @param int $id Post ID to process.
	 * @return void
	 */
	private function _gather_ids_with_post_content( $post_content ): void {
		$block_data = parse_blocks( $post_content );

		foreach ( $block_data as $block ) {
			$this->_process_block( $block );
		}
	}

	/**
	 * Process individual block, and its inner blocks, for IDs to retain.
	 *
	 * @param array $block Parsed block.
	 * @return void
	 */
	private function _process_block( array $block ): void {
		$this->_extract_id( $block );

		foreach ( $block['innerBlocks'] as $inner_block ) {
			$this->_process_block( $inner_block );
		}
	}

	/**
	 * Extract configured IDs from a single block.
	 *
	 * @param array $block Parsed block.
	 * @return void
	 */
	private function _extract_id( array $block ): void {
		if ( ! array_key_exists( $block['blockName'], self::$_block_map ) ) {
			return;
		}

		foreach ( self::$_block_map[ $block['blockName'] ] as $key ) {
			if ( ! str_contains( $key, '.' ) ) {
				if ( ! isset( $block['attrs'][ $key ] ) ) {
					continue;
				}

				if ( is_array( $block['attrs'][ $key ] ) ) {
					$this->_ids[] = $block['attrs'][ $key ];
				} else {
					$this->_ids[] = [ $block['attrs'][ $key ] ];
				}

				continue;
			}

			$parts       = explode( '[]', $key );
			$inner_array = _wp_array_get(
				$block['attrs'],
				explode(
					'.',
					array_shift( $parts )
				)
			);

			if ( empty( $parts ) ) {
				$this->_ids[] = $inner_array;
			} else {
				$thing        = explode(
					'.',
					array_pop( $parts )
				);
				$inner_key    = array_pop( $thing );
				$this->_ids[] = wp_list_pluck( $inner_array, $inner_key );
			}
		}
	}
}
