<?php
/**
 * Runs the sweep on a schedule.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a sweep going in the background.
 *
 * Every run is booked as a single event rather than a recurring one. After a
 * batch the handler decides for itself what to book next: another batch in a
 * minute while there is work left, or the next full run at the configured
 * interval once the site is clean. That is what makes the schedule follow the
 * settings without a recurring event going stale behind it, and it means a
 * sweep interrupted halfway simply continues rather than starting over.
 *
 * WP-Cron is per site, so on multisite every site sweeps itself under whatever
 * policy the network hands it. Nothing here loops over sites.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
class Scheduler {

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
	 * How long to wait before continuing a sweep that has more to do.
	 *
	 * @var int
	 */
	private const CONTINUE_DELAY = MINUTE_IN_SECONDS;

	/**
	 * Hook the schedule into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle_scheduled_event' ) );
		add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
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
		if ( empty( Settings::get( 'cron_enabled' ) ) ) {
			wp_clear_scheduled_hook( self::HOOK );

			return;
		}

		$state = self::state();
		$soon  = time() + self::CONTINUE_DELAY;

		if ( $state['cursor'] > 0 ) {
			$due = $soon;
		} elseif ( $state['finished'] < 1 ) {
			$due = time() + Settings::interval_seconds();
		} else {
			$due = max( $soon, $state['finished'] + Settings::interval_seconds() );
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
	 * @param int $delay Seconds to wait, or 0 for the configured interval.
	 *
	 * @return void
	 */
	public static function reschedule( int $delay = 0 ): void {
		wp_clear_scheduled_hook( self::HOOK );

		if ( empty( Settings::get( 'cron_enabled' ) ) ) {
			return;
		}

		$delay = $delay > 0 ? $delay : Settings::interval_seconds();

		wp_schedule_single_event( time() + $delay, self::HOOK );
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
	 * @return Sweep_Result
	 */
	public function run(): Sweep_Result {
		$state  = self::state();
		$result = ( new Cleaner() )->sweep(
			(int) Settings::get( 'batch_size' ),
			false,
			$state['cursor'],
			array(),
			(int) Settings::get( 'max_deletions' )
		);

		self::advance( Log::SOURCE_CRON, $result );
		self::reschedule( $result->finished ? 0 : self::CONTINUE_DELAY );

		return $result;
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
	 * @param string       $source One of the Log::SOURCE_ constants, used when this batch opens the entry.
	 * @param Sweep_Result $result What the batch did.
	 *
	 * @return void
	 */
	public static function advance( string $source, Sweep_Result $result ): void {
		$state = self::state();
		$entry = Log::record( $source, $result, $state['log'] );

		if ( $result->finished ) {
			update_option(
				self::CURSOR_OPTION,
				array(
					'cursor'    => 0,
					'revisions' => 0,
					'posts'     => 0,
					'started'   => 0,
					'finished'  => time(),
					'removed'   => $state['revisions'] + $result->revisions,
				)
			);

			return;
		}

		update_option(
			self::CURSOR_OPTION,
			array(
				'cursor'    => $result->cursor,
				'revisions' => $state['revisions'] + $result->revisions,
				'posts'     => $state['posts'] + $result->posts,
				'started'   => $state['started'] > 0 ? $state['started'] : time(),
				'finished'  => $state['finished'],
				'removed'   => $state['removed'],
				'log'       => $entry,
			)
		);
	}

	/**
	 * Where the current sweep got to, and what the last one did.
	 *
	 * @return array{cursor: int, revisions: int, posts: int, started: int, finished: int, removed: int, log: int}
	 */
	public static function state(): array {
		$stored = get_option( self::CURSOR_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'cursor'    => (int) ( $stored['cursor'] ?? 0 ),
			'revisions' => (int) ( $stored['revisions'] ?? 0 ),
			'posts'     => (int) ( $stored['posts'] ?? 0 ),
			'started'   => (int) ( $stored['started'] ?? 0 ),
			'finished'  => (int) ( $stored['finished'] ?? 0 ),
			'removed'   => (int) ( $stored['removed'] ?? 0 ),
			'log'       => (int) ( $stored['log'] ?? 0 ),
		);
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
			array(
				'cursor'    => 0,
				'revisions' => 0,
				'posts'     => 0,
				'started'   => 0,
				'finished'  => $state['finished'],
				'removed'   => $state['removed'],
			)
		);
	}
}
