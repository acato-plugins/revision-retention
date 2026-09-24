<?php
/**
 * A record of who removed revisions, and how many.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one row per sweep in a table of its own.
 *
 * A sweep is spread over many requests: the schedule books a batch a minute,
 * the screen asks for batch after batch, and WP-CLI loops. Logging each batch
 * would bury the one line somebody is looking for under dozens that say the
 * same thing, so a sweep opens an entry with its first deletion and every
 * later batch adds to it. The caller holds on to the entry's ID between
 * batches and hands it back.
 *
 * Only deletions are recorded. A preview takes nothing away and a sweep that
 * found nothing to remove has nothing to account for, so neither leaves a row.
 *
 * The table is per site, like the revisions it reports on, and is created the
 * first time there is something to write to it. The network screen reads the
 * tables of every site at once, so the readers below take a flag for that.
 */
class Log {

	/**
	 * Option holding the version of the table this site has.
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'rvrt_log_db_version';

	/**
	 * Cron hook that clears out entries past their retention.
	 *
	 * @var string
	 */
	public const PRUNE_HOOK = 'rvrt_prune_log';

	/**
	 * A sweep the schedule ran.
	 *
	 * @var string
	 */
	public const SOURCE_CRON = 'cron';

	/**
	 * A sweep somebody ran from the settings screen.
	 *
	 * @var string
	 */
	public const SOURCE_SCREEN = 'screen';

	/**
	 * A sweep run from WP-CLI.
	 *
	 * @var string
	 */
	public const SOURCE_CLI = 'cli';

	/**
	 * Version of the table layout below.
	 *
	 * @var string
	 */
	private const DB_VERSION = '1';

	/**
	 * How long an unfinished entry may go without a batch before it counts as
	 * stopped rather than still running.
	 *
	 * The schedule continues a sweep a minute after each batch, and WP-Cron
	 * only fires on a visit, so a quiet site can take a while to get there.
	 *
	 * @var int
	 */
	private const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	/**
	 * Hook the log into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::PRUNE_HOOK, array( self::class, 'prune' ) );
		add_action( 'admin_init', array( $this, 'ensure_pruning' ) );
	}

	/**
	 * Drop the pruning event when the plugin is deactivated.
	 *
	 * @return void
	 */
	public static function on_deactivation(): void {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}

	/**
	 * Keep a daily prune booked for as long as there is a table to prune.
	 *
	 * Pruning is not tied to the sweep: a site that switches the scheduled
	 * sweep off still expects its old entries to go.
	 *
	 * @return void
	 */
	public function ensure_pruning(): void {
		$installed = self::installed();
		$booked    = (bool) wp_next_scheduled( self::PRUNE_HOOK );

		if ( $installed === $booked ) {
			return;
		}

		if ( $installed ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );

			return;
		}

		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}

	/**
	 * Whether this site keeps a log.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return ! empty( Settings::get( 'log_enabled' ) );
	}

	/**
	 * Name of this site's log table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rvrt_log';
	}

	/**
	 * Whether this site has the table, in its current layout.
	 *
	 * @return bool
	 */
	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Create or upgrade the table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() is particular about its input: one column per line, two
		// spaces after PRIMARY KEY, and KEY rather than INDEX.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(20) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				revisions int(10) unsigned NOT NULL DEFAULT 0,
				posts int(10) unsigned NOT NULL DEFAULT 0,
				post_types text NULL,
				finished tinyint(1) unsigned NOT NULL DEFAULT 0,
				started_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				ended_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY ended_gmt (ended_gmt),
				KEY source (source)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Remove the table and everything that points at it.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );

		delete_option( self::DB_VERSION_OPTION );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}

	/**
	 * Add what a batch removed to the log.
	 *
	 * @param string       $source One of the SOURCE_ constants.
	 * @param Sweep_Result $result What the batch did.
	 * @param int          $entry  Entry this batch continues, or 0 to start one.
	 *
	 * @return int The entry holding this sweep, or 0 while there is none.
	 */
	public static function record( string $source, Sweep_Result $result, int $entry = 0 ): int {
		if ( $result->dry_run || ! self::enabled() ) {
			return 0;
		}

		$now     = current_time( 'mysql', true );
		$user_id = get_current_user_id();
		$types   = self::count_types( $result );
		$current = $entry > 0 ? self::find( $entry ) : null;

		// An entry is only carried on by whoever opened it, so an ID posted by
		// somebody else, or left over from another kind of run, starts afresh.
		if ( null !== $current && ( $current['source'] !== $source || (int) $current['user_id'] !== $user_id ) ) {
			$current = null;
		}

		global $wpdb;

		if ( null !== $current ) {
			$stored = json_decode( (string) $current['post_types'], true );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no API above it.
			$wpdb->update(
				self::table(),
				array(
					'revisions'  => (int) $current['revisions'] + $result->revisions,
					'posts'      => (int) $current['posts'] + $result->affected,
					'post_types' => (string) wp_json_encode( self::merge_types( is_array( $stored ) ? $stored : array(), $types ) ),
					'finished'   => $result->finished ? 1 : 0,
					'ended_gmt'  => $now,
				),
				array( 'id' => (int) $current['id'] ),
				array( '%d', '%d', '%s', '%d', '%s' ),
				array( '%d' )
			);

			return (int) $current['id'];
		}

		if ( 0 === $result->revisions ) {
			return 0;
		}

		if ( ! self::installed() ) {
			self::install();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table has no API above it.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'source'      => $source,
				'user_id'     => $user_id,
				'revisions'   => $result->revisions,
				'posts'       => $result->affected,
				'post_types'  => (string) wp_json_encode( $types ),
				'finished'    => $result->finished ? 1 : 0,
				'started_gmt' => $now,
				'ended_gmt'   => $now,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Delete the entries older than the configured retention.
	 *
	 * @return void
	 */
	public static function prune(): void {
		if ( ! self::installed() ) {
			return;
		}

		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - Settings::log_retention_days() * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no API above it.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ended_gmt < %s', self::table(), $cutoff ) );
	}

	/**
	 * One page of entries, newest first.
	 *
	 * Each row carries the `blog_id` of the site it was logged on.
	 *
	 * @param int    $page     Page to fetch, starting at 1.
	 * @param int    $per_page Entries per page.
	 * @param string $source   Only this source, or empty for all of them.
	 * @param bool   $network  Whether to read every site on the network instead of this one.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function entries( int $page, int $per_page, string $source = '', bool $network = false ): array {
		$from = self::from( $network );

		if ( null === $from ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The plugin's own tables, read for display right after a sweep may have written to them. The FROM clause is built from placeholders in from().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$from[0]} WHERE ( '' = %s OR source = %s ) ORDER BY ended_gmt DESC, id DESC LIMIT %d OFFSET %d",
				...array_merge( $from[1], array( $source, $source, $per_page, max( 0, $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$from[0]} WHERE ( '' = %s OR source = %s )",
				...array_merge( $from[1], array( $source, $source ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Revisions removed per day, for the last few days, in the site's timezone.
	 *
	 * @param int  $days    How many days to report, today included.
	 * @param bool $network Whether to read every site on the network instead of this one.
	 *
	 * @return array<string, int> Totals keyed by `Y-m-d`, oldest first, every day present.
	 */
	public static function daily( int $days, bool $network = false ): array {
		$totals = array();
		$today  = new \DateTimeImmutable( 'today', wp_timezone() );

		for ( $offset = $days - 1; $offset >= 0; --$offset ) {
			$totals[ $today->modify( "-{$offset} days" )->format( 'Y-m-d' ) ] = 0;
		}

		$from = self::from( $network );

		if ( null === $from ) {
			return $totals;
		}

		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', $today->modify( '-' . ( $days - 1 ) . ' days' )->getTimestamp() );

		// Grouped here rather than in SQL, which would group by the database's
		// idea of a day instead of the site's.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The plugin's own tables; the FROM clause is built from placeholders in from().
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT ended_gmt, revisions FROM {$from[0]} WHERE ended_gmt >= %s", ...array_merge( $from[1], array( $since ) ) ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$day = wp_date( 'Y-m-d', self::timestamp( (string) $row['ended_gmt'] ) );

			if ( is_string( $day ) && isset( $totals[ $day ] ) ) {
				$totals[ $day ] += (int) $row['revisions'];
			}
		}

		return $totals;
	}

	/**
	 * Totals over everything the log still holds.
	 *
	 * @param bool $network Whether to read every site on the network instead of this one.
	 *
	 * @return array{runs: int, revisions: int, posts: int}
	 */
	public static function totals( bool $network = false ): array {
		$totals = array(
			'runs'      => 0,
			'revisions' => 0,
			'posts'     => 0,
		);

		$from = self::from( $network );

		if ( null === $from ) {
			return $totals;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The plugin's own tables; the FROM clause is built from placeholders in from().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT COUNT(*) AS runs, SUM( revisions ) AS revisions, SUM( posts ) AS posts FROM {$from[0]}", ...$from[1] ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( is_array( $row ) ) {
			$totals['runs']      = (int) $row['runs'];
			$totals['revisions'] = (int) $row['revisions'];
			$totals['posts']     = (int) $row['posts'];
		}

		return $totals;
	}

	/**
	 * Where an entry stands: done, still going, or given up on part way.
	 *
	 * @param array<string, mixed> $entry A row as entries() returns it.
	 *
	 * @return string One of `finished`, `running` or `stopped`.
	 */
	public static function status( array $entry ): string {
		if ( ! empty( $entry['finished'] ) ) {
			return 'finished';
		}

		return time() - self::timestamp( (string) $entry['ended_gmt'] ) < self::STALE_AFTER ? 'running' : 'stopped';
	}

	/**
	 * A stored GMT datetime as a Unix timestamp.
	 *
	 * @param string $datetime Datetime in `Y-m-d H:i:s`, GMT.
	 *
	 * @return int
	 */
	public static function timestamp( string $datetime ): int {
		$time = strtotime( $datetime . ' UTC' );

		return false === $time ? 0 : $time;
	}

	/**
	 * The log tables to read, keyed by the site each belongs to.
	 *
	 * On the network that is every site that has logged something. A site's
	 * table only exists once it had something to write, so the database is
	 * asked which ones are there rather than switching to every site to read
	 * its option.
	 *
	 * @param bool $network Whether every site on the network is meant.
	 *
	 * @return array<int, string>
	 */
	private static function tables( bool $network ): array {
		if ( ! $network || ! is_multisite() ) {
			return self::installed() ? array( get_current_blog_id() => self::table() ) : array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nothing above the schema lists tables.
		$existing = array_flip( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' . $wpdb->esc_like( 'rvrt_log' ) ) ) );
		$tables   = array();

		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $sites as $site_id ) {
			$table = $wpdb->get_blog_prefix( (int) $site_id ) . 'rvrt_log';

			if ( isset( $existing[ $table ] ) ) {
				$tables[ (int) $site_id ] = $table;
			}
		}

		return $tables;
	}

	/**
	 * A FROM clause over the tables to read, with the values its placeholders take.
	 *
	 * Every table is read through one derived table that adds the ID of the
	 * site it came from, so one site and a whole network are queried alike.
	 *
	 * @param bool $network Whether every site on the network is meant.
	 *
	 * @return array{0: string, 1: array<int, int|string>}|null Null when there is nothing to read.
	 */
	private static function from( bool $network ): ?array {
		$tables = self::tables( $network );

		if ( array() === $tables ) {
			return null;
		}

		$args = array();

		foreach ( $tables as $site_id => $table ) {
			$args[] = $site_id;
			$args[] = $table;
		}

		$select = implode( ' UNION ALL ', array_fill( 0, count( $tables ), 'SELECT *, %d AS blog_id FROM %i' ) );

		return array( "( {$select} ) AS rvrt_entries", $args );
	}

	/**
	 * Fetch one entry.
	 *
	 * @param int $entry Entry ID.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function find( int $entry ): ?array {
		if ( ! self::installed() ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read just before it is written to, so a cached copy would lose a batch.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $entry ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Revisions a batch removed, per post type label.
	 *
	 * @param Sweep_Result $result What the batch did.
	 *
	 * @return array<string, int>
	 */
	private static function count_types( Sweep_Result $result ): array {
		$types = array();

		foreach ( $result->items as $item ) {
			$type           = (string) ( $item['type'] ?? '' );
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + (int) ( $item['revisions'] ?? 0 );
		}

		return $types;
	}

	/**
	 * Add one set of per type counts to another.
	 *
	 * @param array<mixed, mixed> $stored What the entry held so far.
	 * @param array<string, int>  $added  What this batch adds.
	 *
	 * @return array<string, int>
	 */
	private static function merge_types( array $stored, array $added ): array {
		$merged = array();

		foreach ( $stored as $type => $count ) {
			$merged[ (string) $type ] = (int) $count;
		}

		foreach ( $added as $type => $count ) {
			$merged[ $type ] = ( $merged[ $type ] ?? 0 ) + $count;
		}

		arsort( $merged );

		return $merged;
	}
}
