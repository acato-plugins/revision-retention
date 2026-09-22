<?php
/**
 * Applies the keep count to WordPress itself.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Tells WordPress how many revisions to keep for a post.
 *
 * Core calls `wp_revisions_to_keep()` every time a revision is saved and
 * removes whatever falls outside the number it gets back, so hooking in here
 * is what stops new revisions from piling up in the first place. The sweep in
 * Cleaner deals with what piled up before.
 */
class Limits {

	/**
	 * Hook the limit into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_revisions_to_keep', array( $this, 'filter_revisions_to_keep' ), 10, 2 );
	}

	/**
	 * Replace the number of revisions WordPress keeps for a post.
	 *
	 * Left untyped on purpose: this is a filter callback in a file under
	 * strict_types, so a loosely typed value from a third party would
	 * otherwise be fatal.
	 *
	 * @param mixed $num  Number of revisions WordPress would keep.
	 * @param mixed $post The post being saved.
	 *
	 * @return mixed
	 */
	public function filter_revisions_to_keep( $num, $post = null ) {
		if ( ! $post instanceof \WP_Post ) {
			return $num;
		}

		// Core zeroes the count for post types without revision support, and
		// that answer outranks any policy: there is nothing to keep.
		if ( ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return $num;
		}

		return Policy::for_post_type( $post->post_type )->keep;
	}
}
