<?php
/**
 * Runs the sweep on a schedule.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a sweep going in the background.
 *
 * Every run is booked as a single event rather than a recurring one. After a
 * batch the handler decides for itself what to book next: another batch in a
 * minute while there is work left, or the next full run at the configured
 * interval, or on the next chosen day of the week, once the site is clean. That is what makes the schedule follow the
 * settings without a recurring event going stale behind it, and it means a
 * sweep interrupted halfway simply continues rather than starting over.
 *
 * WP-Cron is per site, so on multisite every site sweeps itself under whatever
 * policy the network hands it. Nothing here loops over sites.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
class Scheduler extends Singleton {

	/**
	 * Cron hook a booked sweep fires.
	 *
	 * @var string
	 */
	public const HOOK = 'rvrt_sweep';

	/**
	 * Option holding where the current sweep got to.
	 *
	 * @var string
	 */
	public const CURSOR_OPTION = 'rvrt_cursor';

	/**
	 * Option holding when the schedule last ran a batch.
	 *
	 * @var string
	 */
	public const LAST_RUN_OPTION = 'rvrt_last_cron';

	/**
	 * Site transient the network's Dashboard widget keeps where the schedule
	 * stands on every site in.
	 *
	 * @var string
	 */
	public const NETWORK_TRANSIENT = 'rvrt_network_schedule';

	/**
	 * How long to wait before continuing a sweep that has more to do.
	 *
	 * @var int
	 */
	private const CONTINUE_DELAY = MINUTE_IN_SECONDS;

	/**
	 * Option that is there while a real sweep runs on this site.
	 *
	 * @var string
	 */
	public const LOCK_OPTION = 'rvrt_sweep_lock';

	/**
	 * How long a lock holds before it counts as left behind by a request that died.
	 *
	 * A batch takes seconds; WP-CLI renews the lock after every batch it runs.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * The token of the lock this request holds, or empty when it holds none.
	 *
	 * @var string
	 */
	private static string $lock_token = '';

	/**
	 * Hook the schedule into WordPress.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( self::HOOK, [ $this, 'handle_scheduled_event' ] );
		add_action( 'admin_init', [ $this, 'ensure_scheduled' ] );
	}

	/**
	 * Book the first sweep when the plugin is activated.
	 *
	 * @return void
	 */
	public static function on_activation(): void {
		self::reschedule();
	}

	/**
	 * Drop anything booked when the plugin is deactivated.
	 *
	 * @return void
	 */
	public static function on_deactivation(): void {
		// The sweep part way is dropped along with its cursor, so its log
		// entry would otherwise read as in progress.
		Log::stop( self::state()['log'] );
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::CURSOR_OPTION );
		delete_option( self::LAST_RUN_OPTION );

		// The lock is left alone: a WP-CLI sweep may be running right now,
		// and dropping its lock would let a second one start beside it. A
		// lock nobody holds any more expires by itself.
	}

	/**
	 * Make sure a sweep is booked whenever one should be.
	 *
	 * Covers a site whose scheduled event was lost, and a network that turned
	 * the schedule on or off for every site at once.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		$enabled = ! empty( Settings::get( 'cron_enabled' ) );
		$booked  = (bool) wp_next_scheduled( self::HOOK );

		if ( $enabled !== $booked ) {
			self::sync();
		}
	}

	/**
	 * Bring what is booked in line with the settings, without pushing it back.
	 *
	 * Saving the settings used to book the next full sweep an interval from
	 * the save, which threw away the continuation of a sweep that was part way
	 * and moved the rest of it a week out. A sweep part way through now keeps
	 * its continuation, and the next full sweep falls due an interval after
	 * the last one finished. A booking that is already sooner than that stays
	 * where it is, so a shorter interval pulls the next sweep forward and a
	 * longer one takes effect from the sweep after it.
	 *
	 * A site that never finished a sweep counts from now, the same as on
	 * activation, so the first save of a fresh install does not start deleting
	 * within the minute.
	 *
	 * @return void
	 */
	public static function sync(): void {
		self::forget_network();

		if ( empty( Settings::get( 'cron_enabled' ) ) ) {
			wp_clear_scheduled_hook( self::HOOK );

			return;
		}

		$state = self::state();
		$soon  = time() + self::CONTINUE_DELAY;

		if ( $state['cursor'] > 0 ) {
			$due = $soon;
		} elseif ( $state['finished'] < 1 ) {
			$due = Settings::next_run( time() );
		} else {
			$due = max( $soon, Settings::next_run( $state['finished'] ) );
		}

		$booked = (int) wp_next_scheduled( self::HOOK );

		if ( $booked > 0 && $booked <= $due ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( $due, self::HOOK );
	}

	/**
	 * Book the next full sweep, replacing anything already booked.
	 *
	 * @param int  $delay        Seconds to wait, or 0 for the configured interval.
	 * @param bool $continuation Whether this books the next batch of a sweep
	 *                           already under way. The network's Dashboard
	 *                           widget shows such a site as running whenever
	 *                           its next batch is, so what it keeps stays good,
	 *                           and is not dropped every minute of a sweep.
	 *
	 * @return void
	 */
	public static function reschedule( int $delay = 0, bool $continuation = false ): void {
		if ( ! $continuation ) {
			self::forget_network();
		}

		wp_clear_scheduled_hook( self::HOOK );

		if ( empty( Settings::get( 'cron_enabled' ) ) ) {
			return;
		}

		wp_schedule_single_event( $delay > 0 ? time() + $delay : Settings::next_run( time() ), self::HOOK );
	}

	/**
	 * Drop what the network's widget keeps about the schedule, since this
	 * site's part of it is about to change.
	 *
	 * @return void
	 */
	private static function forget_network(): void {
		if ( is_multisite() ) {
			delete_site_transient( self::NETWORK_TRANSIENT );
		}
	}

	/**
	 * Run a booked sweep.
	 *
	 * The cron hook wants a callback that returns nothing, while run() hands
	 * its result back to the callers that care about it.
	 *
	 * @return void
	 */
	public function handle_scheduled_event(): void {
		// Proof that cron actually fires, which the settings screen reports.
		update_option( self::LAST_RUN_OPTION, time(), false );

		$this->run();
	}

	/**
	 * When the schedule last ran a batch on this site.
	 *
	 * @return int Unix timestamp, or 0 when it never has.
	 */
	public static function last_run(): int {
		return (int) get_option( self::LAST_RUN_OPTION, 0 );
	}

	/**
	 * Work through one batch, then decide what to book next.
	 *
	 * @return SweepResult
	 */
	public function run(): SweepResult {
		// Book the continuation first. A batch that dies on a fatal or a time
		// limit would otherwise take the schedule down with it, since the event
		// that started it is already used up.
		self::reschedule( self::CONTINUE_DELAY, true );

		// The screen or WP-CLI is sweeping this site right now. Their batches
		// carry the same cursor and log entry, so running alongside them would
		// delete and count the same revisions twice; try again in a minute.
		if ( ! self::lock() ) {
			return new SweepResult( self::state()['cursor'], 0, 0, false, false );
		}

		// Read only now: until the lock was this request's, another one may
		// have moved the sweep on.
		$state = self::state();

		try {
			$result = ( new Cleaner() )->sweep(
				(int) Settings::get( 'batch_size' ),
				false,
				$state['cursor'],
				[],
				(int) Settings::get( 'max_deletions' )
			);

			// A batch that outlasted the lock may have lost it to another
			// request, which carries the sweep on from here; storing this
			// batch's cursor over theirs would send it back.
			if ( ! self::renew_lock() ) {
				return $result;
			}

			self::advance( Log::SOURCE_CRON, $result );
		} finally {
			self::unlock();
		}

		self::reschedule( $result->finished ? 0 : self::CONTINUE_DELAY, ! $result->finished );

		return $result;
	}

	/**
	 * Work through one batch for somebody who asked for it now: the settings
	 * screen, or the plugin's run-sweep ability.
	 *
	 * A dry run only counts, from the cursor it is given. A real batch always
	 * carries on from the stored cursor rather than one handed in. The
	 * schedule or WP-CLI may have moved the sweep on since the caller's last
	 * batch, and a browser tab left open would otherwise send the shared
	 * cursor back to where that tab last was. When the sweep the caller was
	 * part way through has been finished by somebody else, it is told it is
	 * done instead of starting over. The rest is handed back to the schedule
	 * rather than pushed on here, so a big site does not hold a request open.
	 *
	 * Whoever calls this has checked that the user may: the capability, the
	 * nonce where there is one, and Settings::may_sweep() for a real batch.
	 *
	 * @param string $source  Log source the batch is recorded under.
	 * @param bool   $dry_run Count instead of delete.
	 * @param int    $cursor  Where the caller's previous batch stopped, or 0.
	 *
	 * @return SweepResult|null Null when another request is sweeping this site.
	 */
	public static function run_batch( string $source, bool $dry_run, int $cursor = 0 ): ?SweepResult {
		$cleaner = new Cleaner();
		$size    = (int) Settings::get( 'batch_size' );
		$cap     = (int) Settings::get( 'max_deletions' );

		if ( $dry_run ) {
			return $cleaner->sweep( $size, true, $cursor, [], $cap );
		}

		if ( ! self::lock() ) {
			return null;
		}

		try {
			// Read only now the lock is held, so it is where the sweep really is.
			$stored = self::state()['cursor'];

			if ( $cursor > 0 && 0 === $stored ) {
				return new SweepResult( 0, 0, 0, true, false );
			}

			$result = $cleaner->sweep( $size, false, $stored, [], $cap );

			// A batch that outlasted the lock may have lost it, and the sweep
			// is then another request's to store; this one says so.
			if ( ! self::renew_lock() ) {
				return null;
			}

			self::advance( $source, $result );
		} finally {
			self::unlock();
		}

		self::reschedule( $result->finished ? 0 : MINUTE_IN_SECONDS, ! $result->finished );

		return $result;
	}

	/**
	 * Claim the sweep on this site, so no other request runs a real batch at the same time.
	 *
	 * The option is inserted with INSERT IGNORE, the same way WP_Upgrader takes
	 * its lock: the row's unique name makes the database decide which of two
	 * requests gets it, where add_option() would let both through. Its value
	 * is when it was taken and a token of this request's own, so renewing and
	 * letting go only ever touch this request's lock, never one another
	 * request took over in the meantime. A lock older than LOCK_TTL was left
	 * by a request that died, and is taken over.
	 *
	 * @return bool Whether this request now holds the lock.
	 */
	public static function lock(): bool {
		global $wpdb;

		$token = bin2hex( random_bytes( 8 ) );
		$value = time() . ':' . $token;

		// Twice at most: a lock let go of between the INSERT and the SELECT
		// is gone by the time it is looked at, and is tried for once more.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock has to be decided by the database, never by a cache.
			if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", self::LOCK_OPTION, $value ) ) ) {
				return self::hold( $token );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read past the cache, which may predate the lock.
			$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );

			if ( null === $held ) {
				continue;
			}

			if ( (int) $held > time() - self::LOCK_TTL ) {
				return false;
			}

			// Take over a stale lock only if nobody else did in the meantime.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_OPTION, (string) $held ) );

			return $taken ? self::hold( $token ) : false;
		}

		return false;
	}

	/**
	 * Note that this request holds the lock, under this token.
	 *
	 * @param string $token The token in the lock's value.
	 *
	 * @return true
	 */
	private static function hold( string $token ): bool {
		self::$lock_token = $token;

		self::forget_cached_state();

		return true;
	}

	/**
	 * Hold on to the lock for another LOCK_TTL, for a run of many batches.
	 *
	 * Also the check, before a batch's outcome is stored, that the lock is
	 * still this request's: a batch that ran longer than LOCK_TTL may have
	 * had it taken over.
	 *
	 * @return bool Whether this request still holds the lock.
	 */
	public static function renew_lock(): bool {
		global $wpdb;

		if ( '' === self::$lock_token ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock lives past the cache, see lock().
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", time() . ':' . self::$lock_token, self::LOCK_OPTION, '%:' . $wpdb->esc_like( self::$lock_token ) ) );

		// Read back rather than trusting the affected rows, which MySQL
		// reports as none when the value happens to be unchanged.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );

		if ( str_ends_with( $held, ':' . self::$lock_token ) ) {
			return true;
		}

		self::$lock_token = '';

		return false;
	}

	/**
	 * Let go of the sweep on this site, if this request still holds it.
	 *
	 * @return void
	 */
	public static function unlock(): void {
		global $wpdb;

		if ( '' === self::$lock_token ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock lives past the cache, see lock().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK_OPTION, '%:' . $wpdb->esc_like( self::$lock_token ) ) );

		self::$lock_token = '';
	}

	/**
	 * Have the next read of where the sweep got to come from the database.
	 *
	 * The cursor is autoloaded, so this request holds the copy it loaded when
	 * it started. Another request may have run batches since, and once the
	 * lock is this request's, it has to carry on from where they left off,
	 * never from where the sweep was when this request began.
	 *
	 * @return void
	 */
	private static function forget_cached_state(): void {
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::CURSOR_OPTION, 'options' );
	}

	/**
	 * Log a real batch and store where the sweep got to.
	 *
	 * A sweep is one thing however it is driven. The schedule, Run now and
	 * WP-CLI all carry on from the same cursor, so they also carry on the same
	 * log entry and add to the same totals. That is what keeps a sweep that was
	 * stopped and started again on one line in the log, and what lets the
	 * status say what the last sweep did whoever finished it.
	 *
	 * @param string      $source One of the Log::SOURCE_ constants, used when this batch opens the entry.
	 * @param SweepResult $result What the batch did.
	 *
	 * @return void
	 */
	public static function advance( string $source, SweepResult $result ): void {
		$state = self::state();
		$entry = Log::record( $source, $result, $state['log'] );

		// A sweep that starts or ends here changes how the network's widget
		// counts this site; the batches in between do not.
		if ( $result->finished || 0 === $state['cursor'] ) {
			self::forget_network();
		}

		if ( $result->finished ) {
			update_option(
				self::CURSOR_OPTION,
				[
					'cursor'    => 0,
					'revisions' => 0,
					'posts'     => 0,
					'started'   => 0,
					'finished'  => time(),
					'removed'   => $state['revisions'] + $result->revisions,
				]
			);

			return;
		}

		update_option(
			self::CURSOR_OPTION,
			[
				'cursor'    => $result->cursor,
				'revisions' => $state['revisions'] + $result->revisions,
				'posts'     => $state['posts'] + $result->posts,
				'started'   => $state['started'] > 0 ? $state['started'] : time(),
				'finished'  => $state['finished'],
				'removed'   => $state['removed'],
				'log'       => $entry,
			]
		);
	}

	/**
	 * Where the current sweep got to, and what the last one did.
	 *
	 * @return array{cursor: int, revisions: int, posts: int, started: int, finished: int, removed: int, log: int}
	 */
	public static function state(): array {
		$stored = get_option( self::CURSOR_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'cursor'    => (int) ( $stored['cursor'] ?? 0 ),
			'revisions' => (int) ( $stored['revisions'] ?? 0 ),
			'posts'     => (int) ( $stored['posts'] ?? 0 ),
			'started'   => (int) ( $stored['started'] ?? 0 ),
			'finished'  => (int) ( $stored['finished'] ?? 0 ),
			'removed'   => (int) ( $stored['removed'] ?? 0 ),
			'log'       => (int) ( $stored['log'] ?? 0 ),
		];
	}

	/**
	 * Start the next sweep from the beginning again.
	 *
	 * @return void
	 */
	public static function reset_cursor(): void {
		$state = self::state();

		// The restart opens a log entry of its own; the old one ends here.
		Log::stop( $state['log'] );

		update_option(
			self::CURSOR_OPTION,
			[
				'cursor'    => 0,
				'revisions' => 0,
				'posts'     => 0,
				'started'   => 0,
				'finished'  => $state['finished'],
				'removed'   => $state['removed'],
			]
		);
	}
}
