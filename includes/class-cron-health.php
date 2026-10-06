<?php
/**
 * Whether anything is actually running the schedule.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whether WP-Cron gets to run on this site.
 *
 * Switching the scheduled sweep on only books an event. Something still has
 * to fire it: a page visit, unless `DISABLE_WP_CRON` says otherwise, or a
 * server cron job calling wp-cron.php or WP-CLI. Neither can be asked
 * directly whether it happens, so the queue is read instead. When cron runs,
 * nothing in it is long overdue; when it does not, everything is.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
final class Cron_Health {

	/**
	 * Cron is triggered by page visits and keeps up.
	 *
	 * @var string
	 */
	public const RUNNING = 'running';

	/**
	 * Page visits do not trigger cron, and a server job does.
	 *
	 * @var string
	 */
	public const SYSTEM = 'system';

	/**
	 * Events are overdue, so nothing is running them.
	 *
	 * @var string
	 */
	public const STALLED = 'stalled';

	/**
	 * How late an event may be before cron counts as not running.
	 *
	 * WP-Cron only fires on a visit, so a quiet site is always a little late.
	 * An hour leaves room for that without hiding a cron that never runs.
	 *
	 * @var int
	 */
	private const LATE_AFTER = HOUR_IN_SECONDS;

	/**
	 * Transient holding the overdue event a fresh spawn was given the benefit
	 * of the doubt for.
	 *
	 * @var string
	 */
	public const EXCUSED = 'rvrt_cron_excused';

	/**
	 * Whether page visits trigger WP-Cron on this install.
	 *
	 * @return bool
	 */
	public static function triggered_by_visits(): bool {
		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * How late the most overdue event in the queue is.
	 *
	 * @return int Seconds, or 0 when nothing is due.
	 */
	public static function overdue(): int {
		$oldest = self::oldest();

		return $oldest > 0 ? max( 0, time() - $oldest ) : 0;
	}

	/**
	 * When the event at the front of the queue is due.
	 *
	 * @return int Unix timestamp, or 0 when the queue is empty.
	 */
	private static function oldest(): int {
		$crons = _get_cron_array();

		if ( ! is_array( $crons ) || array() === $crons ) {
			return 0;
		}

		return (int) min( array_keys( $crons ) );
	}

	/**
	 * Whether this very request just set WP-Cron off.
	 *
	 * On a quiet site the first visit after a while finds every event late.
	 * That visit is what fires them, but it does so with a request in the
	 * background, so the queue it reads is still the late one. WordPress
	 * locks cron with the time it spawned it, and a lock from this request
	 * means the events are being run as the screen is drawn.
	 *
	 * @return bool
	 */
	private static function just_spawned(): bool {
		// WordPress notes when it started loading, in timer_start().
		$lock  = (float) get_transient( 'doing_cron' );
		$start = (float) ( $GLOBALS['timestart'] ?? time() );

		return $lock >= $start - 1;
	}

	/**
	 * Whether a late queue is late only because a quiet site just woke up.
	 *
	 * The spawn is excused once per overdue event. Where it works, that event
	 * is gone by the next visit; where the spawn never gets through, the
	 * same event is still at the front of the queue and is reported then.
	 *
	 * @param int $oldest When the most overdue event was due.
	 *
	 * @return bool
	 */
	private static function excused( int $oldest ): bool {
		// Asked more than once in a request, the answer stays the same.
		static $granted = array();

		if ( isset( $granted[ $oldest ] ) ) {
			return true;
		}

		if ( ! self::just_spawned() || (int) get_transient( self::EXCUSED ) === $oldest ) {
			return false;
		}

		set_transient( self::EXCUSED, $oldest, DAY_IN_SECONDS );
		$granted[ $oldest ] = true;

		return true;
	}

	/**
	 * A link to this plugin's events in WP Crontrol, when it is there to use.
	 *
	 * WP Crontrol lists, runs and edits cron events, and its search matches
	 * any part of a hook name, so a search for one hook, or for the shared
	 * prefix, shows exactly the events asked for.
	 *
	 * @param string $search Hook name, or part of one.
	 *
	 * @return string|null Null when WP Crontrol is not active, or its screen is out of the user's reach.
	 */
	public static function crontrol_url( string $search ): ?string {
		if ( ! defined( 'Crontrol\WP_CRONTROL_VERSION' ) || ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		return add_query_arg(
			array(
				'page' => 'wp-crontrol',
				's'    => $search,
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Where the schedule stands.
	 *
	 * @return array{status: string, overdue: int, visits: bool}
	 */
	public static function check(): array {
		$overdue = self::overdue();
		$visits  = self::triggered_by_visits();

		if ( $overdue > self::LATE_AFTER && ! self::excused( self::oldest() ) ) {
			$status = self::STALLED;
		} else {
			$status = $visits ? self::RUNNING : self::SYSTEM;
		}

		return array(
			'status'  => $status,
			'overdue' => $overdue,
			'visits'  => $visits,
		);
	}
}
