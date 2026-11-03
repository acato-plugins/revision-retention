<?php
/**
 * The sweep that removes revisions the policy no longer wants.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention\Includes;

use WP_Post;
use WP_Post_Type;

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
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
class Cleaner {

	/**
	 * Suffix WordPress gives the post_name of an autosave.
	 *
	 * @var string
	 */
	private const AUTOSAVE_SUFFIX = '-autosave-v1';

	/**
	 * What counts() found, for the rest of the request.
	 *
	 * @var array<string, int>|null
	 */
	private static ?array $counts = null;

	/**
	 * Remove one batch of revisions.
	 *
	 * @param int                $batch_size    Posts to work through in this batch.
	 * @param bool               $dry_run       Count what would go without deleting anything.
	 * @param int                $cursor        Parent post ID to resume after.
	 * @param array<int, string> $post_types    Limit the batch to these post types, empty for all.
	 * @param int                $max_deletions Stop once this many revisions have gone, or 0 for no cap.
	 *
	 * @return SweepResult
	 */
	public function sweep( int $batch_size, bool $dry_run = false, int $cursor = 0, array $post_types = [], int $max_deletions = 0 ): SweepResult {
		$rules = Policy::sweepable();

		if ( [] !== $post_types ) {
			$rules = array_intersect_key( $rules, array_flip( $post_types ) );
		}

		if ( [] === $rules ) {
			return new SweepResult( 0, 0, 0, true, $dry_run );
		}

		$candidates = $this->candidates( array_keys( $rules ), $this->loosest_cutoff( $rules ), $cursor, $batch_size );

		if ( [] === $candidates ) {
			return new SweepResult( $cursor, 0, 0, true, $dry_run );
		}

		/**
		 * Filters the posts a sweep must never touch.
		 *
		 * Useful for pages whose full editing history has to be kept, whatever
		 * the retention policy says.
		 *
		 * @param array<int, int> $post_ids Post IDs to leave alone.
		 */
		$excluded = array_map( 'intval', (array) apply_filters( 'revision_retention_excluded_posts', [] ) );

		$removed   = 0;
		$processed = 0;
		$capped    = false;
		$last      = $cursor;
		$affected  = [];
		$revisions = $this->revisions_of( array_diff( array_map( 'intval', array_column( $candidates, 'parent_id' ) ), $excluded ) );

		foreach ( $candidates as $candidate ) {
			$parent_id = (int) ( $candidate['parent_id'] ?? 0 );
			$last      = $parent_id;
			++$processed;

			if ( in_array( $parent_id, $excluded, true ) ) {
				continue;
			}

			$rule = $rules[ (string) ( $candidate['post_type'] ?? '' ) ] ?? null;

			if ( ! $rule instanceof RetentionRule ) {
				continue;
			}

			$outcome  = $this->clean_post( $revisions[ $parent_id ] ?? [], $rule, $dry_run );
			$removed += $outcome['removed'];

			if ( $outcome['removed'] > 0 ) {
				$affected[ $parent_id ] = $outcome;
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

		if ( ! $dry_run && $removed > 0 ) {
			self::$counts = null;
		}

		return new SweepResult(
			$last,
			$processed,
			$removed,
			! $capped && count( $candidates ) < $batch_size,
			$dry_run,
			$this->describe_posts( $affected ),
			count( $affected )
		);
	}

	/**
	 * Name the posts a batch took revisions from.
	 *
	 * The screen lists these so a preview is something to read rather than a
	 * number to trust. Titles are fetched in one go: asking for them post by
	 * post would put a query behind every row.
	 *
	 * @param array<int, array{removed: int, kept: int}> $affected What each post lost and kept, keyed by post ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function describe_posts( array $affected ): array {
		if ( [] === $affected ) {
			return [];
		}

		_prime_post_caches( array_keys( $affected ), false, false );

		$items = [];

		foreach ( $affected as $post_id => $outcome ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$type = get_post_type_object( $post->post_type );

			$items[] = [
				'id'        => $post_id,
				// Titles come back texturised, so an en dash arrives as
				// &#8211;. The screen writes these as text rather than markup,
				// which would show the entity instead of the character.
				'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'type'      => $type instanceof WP_Post_Type ? $type->labels->singular_name : $post->post_type,
				'revisions' => $outcome['removed'],
				// What the post is left with, so the keep floor is visible
				// rather than something to take on trust.
				'kept'      => $outcome['kept'],
				// The screen puts this straight into an href. Core builds it, so
				// it is already an admin URL, but a filter on it is somebody
				// else's code and a javascript: URI there would be a script to
				// run. sanitize_url() allows only the protocols WordPress does.
				'editUrl'   => sanitize_url( (string) get_edit_post_link( $post_id, 'raw' ) ),
			];
		}

		return $items;
	}

	/**
	 * Remove the revisions of one post that fall outside its rule.
	 *
	 * @param array<int, array<string, mixed>> $revisions The post's revisions, newest first, autosaves left out.
	 * @param RetentionRule                    $rule      Rule in effect for this post's type.
	 * @param bool                             $dry_run   Count instead of delete.
	 *
	 * @return array{removed: int, kept: int} What went, and what is left behind.
	 */
	private function clean_post( array $revisions, RetentionRule $rule, bool $dry_run ): array {
		// The newest ones are the floor and are never up for deletion, however
		// old they are. Only what is left over can age out.
		$candidates = array_slice( $revisions, max( 0, $rule->keep ) );

		if ( [] === $candidates ) {
			return [
				'removed' => 0,
				'kept'    => count( $revisions ),
			];
		}

		$cutoff  = $rule->cutoff_gmt();
		$removed = 0;

		foreach ( $candidates as $revision ) {
			if ( (string) ( $revision['post_date_gmt'] ?? '' ) >= $cutoff ) {
				continue;
			}

			// Only what is actually gone counts. A revision another request
			// deleted first, or one a plugin refused to let go, is no longer
			// or still there, and counting it would overstate the sweep.
			if ( ! $dry_run ) {
				$deleted = wp_delete_post_revision( (int) ( $revision['ID'] ?? 0 ) );

				if ( empty( $deleted ) || is_wp_error( $deleted ) ) {
					continue;
				}
			}

			++$removed;
		}

		return [
			'removed' => $removed,
			'kept'    => count( $revisions ) - $removed,
		];
	}

	/**
	 * The most recent cutoff any of the given rules uses.
	 *
	 * Every revision a sweep could delete is older than its own post type's
	 * cutoff, and every cutoff is at or before this one, so it is a safe
	 * pre-filter: it keeps posts whose revisions are all recent out of the
	 * batch entirely instead of paging through them.
	 *
	 * @param array<string, RetentionRule> $rules Rules the sweep runs with.
	 *
	 * @return string Datetime in `Y-m-d H:i:s`.
	 */
	private function loosest_cutoff( array $rules ): string {
		$cutoffs = [];

		foreach ( $rules as $rule ) {
			$cutoffs[] = $rule->cutoff_gmt();
		}

		return [] === $cutoffs ? '' : max( $cutoffs );
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
			[ '%' . $wpdb->esc_like( self::AUTOSAVE_SUFFIX ), $cutoff, $cursor ],
			$post_types,
			[ $limit ]
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

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * The revisions of these posts, newest first, autosaves left out.
	 *
	 * One query for the whole batch rather than one per post.
	 *
	 * @param array<int, int> $post_ids Parent post IDs.
	 *
	 * @return array<int, array<int, array<string, mixed>>> Each post's revisions, keyed by its ID.
	 */
	private function revisions_of( array $post_ids ): array {
		if ( [] === $post_ids ) {
			return [];
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The placeholders are a generated %d list; wp_get_post_revisions() cannot exclude autosaves and would load full post objects the sweep does not need.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent, post_date_gmt
				FROM {$wpdb->posts}
				WHERE post_type = 'revision'
					AND post_parent IN ( {$placeholders} )
					AND post_name NOT LIKE %s
				ORDER BY post_parent ASC, post_date_gmt DESC, ID DESC",
				...array_merge( array_values( $post_ids ), [ '%' . $wpdb->esc_like( self::AUTOSAVE_SUFFIX ) ] )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$grouped = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$grouped[ (int) ( $row['post_parent'] ?? 0 ) ][] = $row;
		}

		return $grouped;
	}

	/**
	 * How far through the site a sweep at this cursor is, from 0 to 1.
	 *
	 * Counted in posts rather than IDs: the share of the posts of the types
	 * the policy sweeps that lie at or before the cursor. Post IDs are spread
	 * unevenly, so measuring the cursor against the highest ID crawled
	 * through a dense stretch and then leapt ahead. Every post is counted,
	 * whether it has revisions or not, so what a real run deletes behind the
	 * cursor does not move it; counting only posts with revisions let a policy
	 * that keeps none take swept posts out of both sides, and the bar sat near
	 * the start until it jumped to the end.
	 *
	 * @param int                $cursor     Parent post ID the sweep has got to.
	 * @param array<int, string> $post_types Limit to these post types, empty for all the policy sweeps.
	 *
	 * @return float
	 */
	public static function progress( int $cursor, array $post_types = [] ): float {
		$types = array_keys( Policy::sweepable() );

		if ( [] !== $post_types ) {
			$types = array_values( array_intersect( $types, $post_types ) );
		}

		if ( [] === $types ) {
			return 1.0;
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The only interpolation is a generated list of %s placeholders; read per batch to size its progress, where a cached value would freeze the bar.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM( CASE WHEN ID <= %d THEN 1 ELSE 0 END ) AS done
				FROM {$wpdb->posts}
				WHERE post_type IN ( {$placeholders} )",
				...array_merge( [ $cursor ], $types )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$total = is_array( $row ) ? (int) $row['total'] : 0;

		return $total > 0 ? min( 1.0, (int) $row['done'] / $total ) : 1.0;
	}

	/**
	 * How many revisions are stored, per post type.
	 *
	 * The settings screen shows these twice, among the stats and in the post
	 * types table, and the query joins every revision to its parent, so the
	 * answer is kept for the rest of the request. A real sweep drops it.
	 *
	 * @return array<string, int> Counts keyed by post type slug.
	 */
	public static function counts(): array {
		if ( null !== self::$counts ) {
			return self::$counts;
		}

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

		$counts = [];

		foreach ( $rows as $row ) {
			$counts[ (string) ( $row['post_type'] ?? '' ) ] = (int) ( $row['total'] ?? 0 );
		}

		self::$counts = $counts;

		return $counts;
	}
}
