<?php
/**
 * The sweep that removes revisions the policy no longer wants.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Works through posts in batches, removing the revisions that fall outside the
 * retention rule of their post type.
 *
 * Deletion goes through `wp_delete_post_revision()` rather than a bulk query,
 * so post meta and term relationships are cleaned up the way WordPress expects
 * and other plugins see the hooks they are listening for. That is slower per
 * revision, which is exactly why the work is batched and resumable: a sweep
 * that runs out of time picks up where it left off instead of starting over.
 */
class Cleaner {

	/**
	 * Suffix WordPress gives the post_name of an autosave.
	 *
	 * @var string
	 */
	private const AUTOSAVE_SUFFIX = '-autosave-v1';

	/**
	 * Remove one batch of revisions.
	 *
	 * @param int                $batch_size    Posts to work through in this batch.
	 * @param bool               $dry_run       Count what would go without deleting anything.
	 * @param int                $cursor        Parent post ID to resume after.
	 * @param array<int, string> $post_types    Limit the batch to these post types, empty for all.
	 * @param int                $max_deletions Stop once this many revisions have gone, or 0 for no cap.
	 *
	 * @return Sweep_Result
	 */
	public function sweep( int $batch_size, bool $dry_run = false, int $cursor = 0, array $post_types = array(), int $max_deletions = 0 ): Sweep_Result {
		$rules = Policy::sweepable();

		if ( array() !== $post_types ) {
			$rules = array_intersect_key( $rules, array_flip( $post_types ) );
		}

		if ( array() === $rules ) {
			return new Sweep_Result( 0, 0, 0, true, $dry_run );
		}

		$candidates = $this->candidates( array_keys( $rules ), $this->loosest_cutoff( $rules ), $cursor, $batch_size );

		if ( array() === $candidates ) {
			return new Sweep_Result( $cursor, 0, 0, true, $dry_run );
		}

		/**
		 * Filters the posts a sweep must never touch.
		 *
		 * Useful for pages whose full editing history has to be kept, whatever
		 * the retention policy says.
		 *
		 * @param array<int, int> $post_ids Post IDs to leave alone.
		 */
		$excluded = array_map( 'intval', (array) apply_filters( 'revision_retention_excluded_posts', array() ) );

		$removed   = 0;
		$processed = 0;
		$capped    = false;
		$last      = $cursor;
		$affected  = array();

		foreach ( $candidates as $candidate ) {
			$parent_id = (int) ( $candidate['parent_id'] ?? 0 );
			$last      = $parent_id;
			++$processed;

			if ( in_array( $parent_id, $excluded, true ) ) {
				continue;
			}

			$rule = $rules[ (string) ( $candidate['post_type'] ?? '' ) ] ?? null;

			if ( ! $rule instanceof Retention_Rule ) {
				continue;
			}

			$took     = $this->clean_post( $parent_id, $rule, $dry_run );
			$removed += $took;

			if ( $took > 0 ) {
				$affected[ $parent_id ] = $took;
			}

			// The cap is checked between posts rather than inside one. Stopping
			// half way through a post would move the cursor past revisions that
			// still fall outside the policy, and nothing would come back for
			// them until the sweep started over. Overshooting the cap by one
			// post is the cheaper mistake.
			if ( $max_deletions > 0 && $removed >= $max_deletions ) {
				$capped = true;

				break;
			}
		}

		return new Sweep_Result(
			$last,
			$processed,
			$removed,
			! $capped && count( $candidates ) < $batch_size,
			$dry_run,
			$this->describe_posts( $affected )
		);
	}

	/**
	 * Name the posts a batch took revisions from.
	 *
	 * The screen lists these so a preview is something to read rather than a
	 * number to trust. Titles are fetched in one go: asking for them post by
	 * post would put a query behind every row.
	 *
	 * @param array<int, int> $affected Revisions taken, keyed by post ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function describe_posts( array $affected ): array {
		if ( array() === $affected ) {
			return array();
		}

		_prime_post_caches( array_keys( $affected ), false, false );

		$items = array();

		foreach ( $affected as $post_id => $revisions ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$type = get_post_type_object( $post->post_type );

			$items[] = array(
				'id'        => $post_id,
				'title'     => get_the_title( $post ),
				'type'      => $type instanceof \WP_Post_Type ? $type->labels->singular_name : $post->post_type,
				'revisions' => $revisions,
				'editUrl'   => (string) get_edit_post_link( $post_id, 'raw' ),
			);
		}

		return $items;
	}

	/**
	 * Remove the revisions of one post that fall outside its rule.
	 *
	 * @param int            $post_id Parent post ID.
	 * @param Retention_Rule $rule    Rule in effect for this post's type.
	 * @param bool           $dry_run Count instead of delete.
	 *
	 * @return int Revisions removed, or that would have been removed.
	 */
	private function clean_post( int $post_id, Retention_Rule $rule, bool $dry_run ): int {
		$revisions = $this->revisions_of( $post_id );

		// The newest ones are the floor and are never up for deletion, however
		// old they are. Only what is left over can age out.
		$candidates = array_slice( $revisions, max( 0, $rule->keep ) );

		if ( array() === $candidates ) {
			return 0;
		}

		$cutoff  = $rule->cutoff_gmt();
		$removed = 0;

		foreach ( $candidates as $revision ) {
			if ( (string) ( $revision['post_date_gmt'] ?? '' ) >= $cutoff ) {
				continue;
			}

			if ( ! $dry_run ) {
				wp_delete_post_revision( (int) ( $revision['ID'] ?? 0 ) );
			}

			++$removed;
		}

		return $removed;
	}

	/**
	 * The most recent cutoff any of the given rules uses.
	 *
	 * Every revision a sweep could delete is older than its own post type's
	 * cutoff, and every cutoff is at or before this one, so it is a safe
	 * pre-filter: it keeps posts whose revisions are all recent out of the
	 * batch entirely instead of paging through them.
	 *
	 * @param array<string, Retention_Rule> $rules Rules the sweep runs with.
	 *
	 * @return string Datetime in `Y-m-d H:i:s`.
	 */
	private function loosest_cutoff( array $rules ): string {
		$cutoffs = array();

		foreach ( $rules as $rule ) {
			$cutoffs[] = $rule->cutoff_gmt();
		}

		return array() === $cutoffs ? '' : max( $cutoffs );
	}

	/**
	 * The posts with revisions old enough to be worth looking at.
	 *
	 * @param array<int, string> $post_types Post types to consider.
	 * @param string             $cutoff     Only posts with a revision older than this.
	 * @param int                $cursor     Parent post ID to resume after.
	 * @param int                $limit      Maximum number of posts to return.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function candidates( array $post_types, string $cutoff, int $cursor, int $limit ): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$arguments    = array_merge(
			array( '%' . $wpdb->esc_like( self::AUTOSAVE_SUFFIX ), $cutoff, $cursor ),
			$post_types,
			array( $limit )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The only interpolation is a generated list of %s placeholders.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The number of placeholders follows the number of post types, which is only known at runtime.
		$sql = $wpdb->prepare(
			"SELECT r.post_parent AS parent_id, p.post_type AS post_type
			FROM {$wpdb->posts} r
			INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent
			WHERE r.post_type = 'revision'
				AND r.post_name NOT LIKE %s
				AND r.post_date_gmt < %s
				AND r.post_parent > %d
				AND p.post_type IN ( {$placeholders} )
			GROUP BY r.post_parent, p.post_type
			ORDER BY r.post_parent ASC
			LIMIT %d",
			...$arguments
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Revisions have no core API to query them across posts, and a sweep must see the current rows rather than a cached set.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every stored revision of a post, newest first, autosaves left out.
	 *
	 * An autosave is somebody's unsaved work rather than a point in the
	 * history, so it is never a candidate for removal.
	 *
	 * @param int $post_id Parent post ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function revisions_of( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wp_get_post_revisions() cannot exclude autosaves by date and would load full post objects the sweep does not need.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_date_gmt
				FROM {$wpdb->posts}
				WHERE post_type = 'revision'
					AND post_parent = %d
					AND post_name NOT LIKE %s
				ORDER BY post_date_gmt DESC, ID DESC",
				$post_id,
				'%' . $wpdb->esc_like( self::AUTOSAVE_SUFFIX )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The highest post ID that has revisions at all.
	 *
	 * The sweep walks posts in ID order, so the cursor against this is a fair
	 * measure of how far through a run is. It is an estimate, not a count of
	 * work left, which is all a progress bar needs.
	 *
	 * @return int
	 */
	public static function last_parent_id(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read once per sweep to size its progress; a stale value would misreport it.
		return (int) $wpdb->get_var(
			"SELECT MAX( post_parent ) FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);
	}

	/**
	 * How many revisions are stored, per post type.
	 *
	 * @return array<string, int> Counts keyed by post type slug.
	 */
	public static function counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reported on the settings screen and by WP-CLI, where a stale count would be misleading.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_type AS post_type, COUNT(*) AS total
				FROM {$wpdb->posts} r
				INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent
				WHERE r.post_type = 'revision'
					AND r.post_name NOT LIKE %s
				GROUP BY p.post_type",
				'%' . $wpdb->esc_like( self::AUTOSAVE_SUFFIX )
			),
			ARRAY_A
		);

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (string) ( $row['post_type'] ?? '' ) ] = (int) ( $row['total'] ?? 0 );
		}

		return $counts;
	}
}
