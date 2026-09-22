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
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::CURSOR_OPTION );
	}

	/**
	 * Make sure a sweep is booked whenever one should be.
	 *
	 * Covers a site whose scheduled event was lost, and picks up a change to
	 * the settings without the save handler having to think about it.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		$enabled = ! empty( Settings::get( 'cron_enabled' ) );
		$booked  = (bool) wp_next_scheduled( self::HOOK );

		if ( $enabled === $booked ) {
			return;
		}

		if ( $enabled ) {
			self::reschedule();

			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
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
		$this->run();
	}

	/**
	 * Work through one batch, then decide what to book next.
	 *
	 * @return Sweep_Result
	 */
	public function run(): Sweep_Result {
		$state  = self::state();
		$result = ( new Cleaner() )->sweep( (int) Settings::get( 'batch_size' ), false, $state['cursor'] );

		if ( $result->finished ) {
			self::remember_finished_run( $state, $result );
			self::reschedule();

			return $result;
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
			)
		);

		self::reschedule( self::CONTINUE_DELAY );

		return $result;
	}

	/**
	 * Where the current sweep got to, and what the last one did.
	 *
	 * @return array{cursor: int, revisions: int, posts: int, started: int, finished: int, removed: int}
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
		);
	}

	/**
	 * Start the next sweep from the beginning again.
	 *
	 * @return void
	 */
	public static function reset_cursor(): void {
		$state = self::state();

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

	/**
	 * Record a completed sweep and clear the cursor for the next one.
	 *
	 * @param array{cursor: int, revisions: int, posts: int, started: int, finished: int, removed: int} $state  State before this batch.
	 * @param Sweep_Result                                                                              $result What the last batch did.
	 *
	 * @return void
	 */
	private static function remember_finished_run( array $state, Sweep_Result $result ): void {
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
	}
}
