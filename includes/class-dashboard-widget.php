<?php
/**
 * The recent sweeps at a glance, on the Dashboard.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * A Dashboard widget with the totals and the chart of the last two weeks.
 *
 * Only while the site switches it on, and only for administrators and the
 * roles ticked for it. It is a glance, not the log: there is no filter and no
 * timeline here, just a link to the Logs tab for whoever wants to know more.
 *
 * On the network Dashboard it shows every site together, the way the network
 * Logs tab does, to the network administrators who can open that tab. A
 * network administrator on a site's Dashboard gets both, as a Site and a
 * Network tab, unless the site is the only one on the network that is live
 * and runs the plugin: then the network is the site, and the widget is the
 * one a single site gets.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
class Dashboard_Widget {

	/**
	 * ID of the widget.
	 *
	 * @var string
	 */
	private const ID = 'rvrt_recent_sweeps';

	/**
	 * Days the widget covers, when it shows a bar per day.
	 *
	 * @var int
	 */
	private const DAYS = 14;

	/**
	 * The most weeks or months the widget covers, when it shows a bar per week
	 * or per month.
	 *
	 * @var int
	 */
	private const MAX_PERIODS = 12;

	/**
	 * The fewest weeks or months it covers, however short the log is kept.
	 *
	 * @var int
	 */
	private const MIN_PERIODS = 3;

	/**
	 * How long that is kept, in seconds.
	 *
	 * @var int
	 */
	private const SCHEDULE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Hook the widget into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
		add_action( 'wp_network_dashboard_setup', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the widget, when this user is to see it.
	 *
	 * @return void
	 */
	public function add(): void {
		if ( ! self::is_shown() ) {
			return;
		}

		wp_add_dashboard_widget(
			self::ID,
			_x( 'Revision Retention', 'dashboard widget title', 'revision-retention' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Load the stylesheet the chart is drawn with, on the Dashboard only.
	 *
	 * @param mixed $hook_suffix Screen the hook fired for.
	 *
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( 'index.php' !== $hook_suffix || ! self::is_shown() ) {
			return;
		}

		$style = Assets::url( 'src/settings.css' );

		if ( null !== $style ) {
			wp_enqueue_style( 'rvrt-settings', $style, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The hash in the built file name, or the ver on a source, is the version.
		}

		// The chart's tooltips, and the widget's tabs and period switch.
		foreach ( array(
			'rvrt-chart'  => 'src/chart.js',
			'rvrt-widget' => 'src/widget.js',
		) as $handle => $source ) {
			$script = Assets::url( $source );

			if ( null !== $script ) {
				wp_enqueue_script( $handle, $script, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The hash in the built file name, or the ver on a source, is the version.
			}
		}
	}

	/**
	 * Render the widget.
	 *
	 * Wrapped in the settings screen's class, so the chart and the totals
	 * look exactly as they do on the Logs tab.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div class="rvrt-settings rvrt-widget">';

		if ( self::has_network_tab() ) {
			$this->render_tabs();
		} else {
			self::render_view( is_network_admin() );
		}

		echo '</div>';
	}

	/**
	 * The Site and Network views, as tabs.
	 *
	 * The tab names the view, so the views carry no heading of their own. The
	 * Site view is the one shown first; the script makes the tabs switch, the
	 * way the Dashboard's own widgets need it to fold and move.
	 *
	 * @return void
	 */
	private function render_tabs(): void {
		$views = array(
			'site'    => _x( 'Site', 'dashboard widget tab', 'revision-retention' ),
			'network' => _x( 'Network', 'dashboard widget tab', 'revision-retention' ),
		);
		?>
		<div class="rvrt-widget-tabs" role="tablist" aria-label="<?php echo esc_attr_x( 'Recent sweeps', 'accessibility label', 'revision-retention' ); ?>">
			<?php foreach ( $views as $view => $label ) : ?>
				<button
					type="button"
					class="rvrt-widget-tab"
					role="tab"
					id="rvrt-widget-tab-<?php echo esc_attr( $view ); ?>"
					aria-controls="rvrt-widget-panel-<?php echo esc_attr( $view ); ?>"
					aria-selected="<?php echo 'site' === $view ? 'true' : 'false'; ?>"
					tabindex="<?php echo 'site' === $view ? '0' : '-1'; ?>"
				><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php foreach ( $views as $view => $label ) : ?>
			<div class="rvrt-widget-panel" id="rvrt-widget-panel-<?php echo esc_attr( $view ); ?>" role="tabpanel" aria-labelledby="rvrt-widget-tab-<?php echo esc_attr( $view ); ?>"<?php echo 'site' === $view ? '' : ' hidden'; ?>>
				<?php self::render_view( 'network' === $view ); ?>
			</div>
			<?php
		endforeach;
	}

	/**
	 * The totals and the chart for this site, or for every site.
	 *
	 * @param bool $network Whether every site on the network is meant.
	 *
	 * @return void
	 */
	private static function render_view( bool $network ): void {
		$logs = add_query_arg(
			array(
				'page'     => Settings_Page::PAGE_SLUG,
				'rvrt-tab' => 'logs',
			),
			$network ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' )
		);

		[ $current, $periods ] = self::periods( $network );

		$schedule = current_user_can( Settings::capability( $network ) )
			? add_query_arg(
				array(
					'page'     => Settings_Page::PAGE_SLUG,
					'rvrt-tab' => 'schedule',
				),
				$network ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' )
			)
			: '';

		if ( $network ) {
			$sites = self::network_schedule();
			$next  = self::describe_network_next( $sites );
			$stall = self::describe_stall( $sites['enabled'] > 0 );
		} else {
			$next  = self::describe_next();
			$stall = self::describe_stall( ! empty( Settings::get( 'cron_enabled' ) ) );
		}

		( new Log_View( $logs, $network ) )->render_recent( $periods, $current, $logs, $next, $stall, $schedule );
	}

	/**
	 * Why the next sweep will not happen, when WP-Cron is not running.
	 *
	 * The date alone says a run is overdue, not that nothing on the site can
	 * start it. This is the same check the Schedule tab warns from, so the
	 * two never disagree. Shown even before this plugin's own run falls due:
	 * with the rest of the queue stuck, it will not run either.
	 *
	 * On the network it is the check for the site the screen belongs to.
	 * Whether visits start WP-Cron is the same for every site, and a queue
	 * stuck on one is rarely moving on the others.
	 *
	 * @param bool $enabled Whether the schedule is on anywhere this view covers.
	 *
	 * @return string The hint, or empty when cron runs or the schedule is off.
	 */
	private static function describe_stall( bool $enabled ): string {
		if ( ! $enabled || Cron_Health::STALLED !== Cron_Health::check()['status'] ) {
			return '';
		}

		return Cron_Health::triggered_by_visits()
			? _x( 'WP-Cron does not seem to be running, so scheduled cleanups wait. It is started by visits to the site, so a site nobody visits, or a server that blocks requests to itself, holds it up.', 'dashboard widget', 'revision-retention' )
			: _x( 'WP-Cron does not seem to be running, so scheduled cleanups wait. DISABLE_WP_CRON is set, so visits do not start it and a server cron job has to.', 'dashboard widget', 'revision-retention' );
	}

	/**
	 * The periods the chart can be switched between, how many bars each gets,
	 * and the one it starts on.
	 *
	 * It starts on a bar per day for a sweep that runs daily or more often,
	 * but a weekly or monthly sweep would leave a fortnight of days mostly
	 * empty, so those start on a bar per week or per month. Sweeping on a
	 * single chosen day of the week is weekly too. Weeks and months stop at
	 * what the log still keeps, so the chart does not end in bars that can
	 * only ever be empty.
	 *
	 * The network view goes by the network's own settings, which is what a
	 * site inherits unless it says otherwise.
	 *
	 * @param bool $network Whether every site on the network is meant.
	 *
	 * @return array{0: string, 1: array<string, int>}
	 */
	private static function periods( bool $network ): array {
		$settings = $network ? Settings::network() : Settings::resolved();
		$interval = (string) ( $settings['cron_interval'] ?? '' );
		$days     = is_array( $settings['cron_days'] ?? null ) ? $settings['cron_days'] : array();
		$kept     = Settings::log_retention_days( (string) ( $settings['log_retention'] ?? '' ) );

		$periods = array(
			Log::PERIOD_DAY   => self::DAYS,
			Log::PERIOD_WEEK  => min( self::MAX_PERIODS, max( self::MIN_PERIODS, (int) ceil( $kept / 7 ) ) ),
			Log::PERIOD_MONTH => min( self::MAX_PERIODS, max( self::MIN_PERIODS, (int) ceil( $kept / 30 ) ) ),
		);

		$current = match ( true ) {
			'monthly' === $interval => Log::PERIOD_MONTH,
			in_array( $interval, array( 'weekly', 'fortnightly' ), true ), Settings::WEEKDAYS === $interval && count( $days ) < 2 => Log::PERIOD_WEEK,
			default => Log::PERIOD_DAY,
		};

		return array( $current, $periods );
	}

	/**
	 * When the next scheduled sweep is expected on this site, in a sentence.
	 *
	 * @return string
	 */
	private static function describe_next(): string {
		if ( empty( Settings::get( 'cron_enabled' ) ) ) {
			return _x( 'Scheduled cleanups are switched off.', 'dashboard widget', 'revision-retention' );
		}

		if ( Scheduler::state()['cursor'] > 0 ) {
			return _x( 'A cleanup is under way and carries on in the background.', 'dashboard widget', 'revision-retention' );
		}

		$next = (int) wp_next_scheduled( Scheduler::HOOK );

		if ( $next < 1 ) {
			return _x( 'No cleanup run is booked yet.', 'dashboard widget', 'revision-retention' );
		}

		return $next <= time()
			/* translators: %s: when, e.g. "Thursday 15 October at 09:47". */
			? sprintf( _x( 'The next cleanup was planned for %s and has not run yet.', 'dashboard widget', 'revision-retention' ), self::describe_when( $next ) )
			/* translators: %s: when, e.g. "today at 12:26" or "Monday 2 November at 03:25". */
			: sprintf( _x( 'The next cleanup is planned for %s.', 'dashboard widget', 'revision-retention' ), self::describe_when( $next ) );
	}

	/**
	 * Where the schedule stands on every site the network view covers.
	 *
	 * Each site books its own sweep, so this has to look at every one of
	 * them. That is kept for a few minutes rather than done on every load of
	 * the Dashboard, which on a large network would be a lot of switching,
	 * and dropped as soon as any site books or clears a sweep.
	 *
	 * @return array{enabled: int, running: int, overdue: int, next: int, site: string}
	 */
	private static function network_schedule(): array {
		$cached = get_site_transient( Scheduler::NETWORK_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$schedule = array(
			'enabled' => 0,
			'running' => 0,
			'overdue' => 0,
			'next'    => 0,
			'site'    => '',
		);

		foreach ( Plugin::sites() as $site_id ) {
			switch_to_blog( $site_id );

			if ( ! empty( Settings::get( 'cron_enabled' ) ) ) {
				++$schedule['enabled'];

				$next = (int) wp_next_scheduled( Scheduler::HOOK );

				if ( Scheduler::state()['cursor'] > 0 ) {
					++$schedule['running'];
				} elseif ( $next > 0 && $next <= time() ) {
					++$schedule['overdue'];
				} elseif ( $next > 0 && ( 0 === $schedule['next'] || $next < $schedule['next'] ) ) {
					$schedule['next'] = $next;
					$schedule['site'] = (string) get_option( 'blogname' );
				}
			}

			restore_current_blog();
		}

		set_site_transient( Scheduler::NETWORK_TRANSIENT, $schedule, self::SCHEDULE_TTL );

		return $schedule;
	}

	/**
	 * When the next scheduled sweep is expected anywhere on the network, in
	 * a sentence or two.
	 *
	 * @param array{enabled: int, running: int, overdue: int, next: int, site: string} $schedule See self::network_schedule().
	 *
	 * @return string
	 */
	private static function describe_network_next( array $schedule ): string {
		if ( 0 === $schedule['enabled'] ) {
			return _x( 'Scheduled cleanups are switched off on every site.', 'dashboard widget', 'revision-retention' );
		}

		$sentences = array();

		if ( $schedule['next'] > 0 ) {
			$sentences[] = sprintf(
				/* translators: 1: when, e.g. "today at 12:26" or "Monday 2 November at 03:25", 2: site name. */
				_x( 'The next cleanup on the network is planned for %1$s, on %2$s.', 'dashboard widget', 'revision-retention' ),
				self::describe_when( $schedule['next'] ),
				$schedule['site']
			);
		}

		if ( $schedule['running'] > 0 ) {
			$sentences[] = sprintf(
				/* translators: %s: number of sites. */
				_nx( 'A cleanup is under way on %s site.', 'A cleanup is under way on %s sites.', $schedule['running'], 'dashboard widget', 'revision-retention' ),
				number_format_i18n( $schedule['running'] )
			);
		}

		if ( $schedule['overdue'] > 0 ) {
			$sentences[] = sprintf(
				/* translators: %s: number of sites. */
				_nx( 'On %s site the cleanup is overdue.', 'On %s sites the cleanup is overdue.', $schedule['overdue'], 'dashboard widget', 'revision-retention' ),
				number_format_i18n( $schedule['overdue'] )
			);
		}

		return array() !== $sentences
			? implode( ' ', $sentences )
			: _x( 'No cleanup run is booked yet.', 'dashboard widget', 'revision-retention' );
	}

	/**
	 * When something happens, in the words people use for it: today or
	 * tomorrow at a time, or a day with its weekday, and its year only when it
	 * is not this one.
	 *
	 * @param int $timestamp The moment.
	 *
	 * @return string
	 */
	private static function describe_when( int $timestamp ): string {
		$day  = wp_date( 'Y-m-d', $timestamp );
		$time = (string) wp_date( Settings::time_format(), $timestamp );

		if ( wp_date( 'Y-m-d' ) === $day ) {
			/* translators: %s: time. */
			return sprintf( _x( 'today at %s', 'dashboard widget, when', 'revision-retention' ), $time );
		}

		if ( wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) === $day ) {
			/* translators: %s: time. */
			return sprintf( _x( 'tomorrow at %s', 'dashboard widget, when', 'revision-retention' ), $time );
		}

		$format = wp_date( 'Y', $timestamp ) === wp_date( 'Y' )
			? _x( 'l j F', 'dashboard widget day, this year', 'revision-retention' )
			: _x( 'l j F Y', 'dashboard widget day, another year', 'revision-retention' );

		/* translators: 1: day, e.g. "Monday 2 November", 2: time. */
		return sprintf( _x( '%1$s at %2$s', 'dashboard widget, when', 'revision-retention' ), (string) wp_date( $format, $timestamp ), $time );
	}

	/**
	 * Whether this is a site's Dashboard seen by a network administrator,
	 * who gets the whole network next to the site.
	 *
	 * Only when the network is more than this one site, so a network that
	 * holds a single published site gets the widget a single site gets.
	 *
	 * @return bool
	 */
	private static function has_network_tab(): bool {
		return is_multisite()
			&& ! is_network_admin()
			&& current_user_can( Settings::capability( true ) )
			&& self::published_sites() > 1;
	}

	/**
	 * How many sites the network view covers that are published.
	 *
	 * The sites the plugin runs on, less those archived, marked as spam or
	 * deleted. A site that discourages search engines still counts: that is
	 * how a staging network is often set up, and it should look like live.
	 *
	 * @return int
	 */
	private static function published_sites(): int {
		$published = array_map(
			'intval',
			(array) get_sites(
				array(
					'fields'   => 'ids',
					'number'   => 0,
					'archived' => 0,
					'spam'     => 0,
					'deleted'  => 0,
				)
			)
		);

		return count( array_intersect( Plugin::sites(), $published ) );
	}

	/**
	 * Whether the widget is switched on and this user may see it.
	 *
	 * On a site, that is the site's own setting and its roles. On the network,
	 * the network's setting, for its administrators.
	 *
	 * @return bool
	 */
	private static function is_shown(): bool {
		if ( is_network_admin() ) {
			return ! empty( Settings::network()['dashboard_widget'] ) && current_user_can( Settings::capability( true ) );
		}

		return ! empty( Settings::get( 'dashboard_widget' ) ) && Settings::may_view_log();
	}
}
