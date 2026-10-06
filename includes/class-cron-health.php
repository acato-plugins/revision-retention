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
		$crons = _get_cron_array();

		if ( ! is_array( $crons ) || array() === $crons ) {
			return 0;
		}

		$oldest = (int) min( array_keys( $crons ) );

		return max( 0, time() - $oldest );
	}

	/**
	 * Where the schedule stands.
	 *
	 * @return array{status: string, overdue: int, visits: bool}
	 */
	public static function check(): array {
		$overdue = self::overdue();
		$visits  = self::triggered_by_visits();

		if ( $overdue > self::LATE_AFTER ) {
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
