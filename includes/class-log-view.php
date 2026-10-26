<?php
/**
 * The log, drawn as a timeline.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Logs tab: a line of totals, a bar per day, and every sweep on a
 * timeline grouped by the day it happened, newest first.
 *
 * Everything here is read only and works without JavaScript. Filtering and
 * paging are links, so a filtered page can be bookmarked or sent on.
 *
 * On the network screen the same timeline runs over every site's log at once,
 * and each entry names the site it was swept on.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
class Log_View {

	/**
	 * Entries per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 25;

	/**
	 * Days the activity chart covers.
	 *
	 * @var int
	 */
	private const CHART_DAYS = 30;

	/**
	 * Query argument naming the page of entries.
	 *
	 * @var string
	 */
	private const PAGE_ARG = 'rvrt-log-page';

	/**
	 * Query argument naming the source filtered on.
	 *
	 * @var string
	 */
	private const SOURCE_ARG = 'rvrt-log-source';

	/**
	 * Build the view.
	 *
	 * @param string $base_url URL of the Logs tab, which filters and pages hang off.
	 * @param bool   $network  Whether to show every site on the network rather than this one.
	 */
	public function __construct( private readonly string $base_url, private readonly bool $network = false ) {}

	/**
	 * Render the whole timeline.
	 *
	 * @return void
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only, and validated below.
		$page   = isset( $_GET[ self::PAGE_ARG ] ) ? max( 1, absint( wp_unslash( $_GET[ self::PAGE_ARG ] ) ) ) : 1;
		$source = isset( $_GET[ self::SOURCE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::SOURCE_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( self::sources()[ $source ] ) ) {
			$source = '';
		}

		$totals = Log::totals( $this->network );

		if ( 0 === $totals['runs'] ) {
			$this->render_empty();

			return;
		}

		$entries = Log::entries( $page, self::PER_PAGE, $source, $this->network );
		$setting = $this->network ? Settings::network()['log_retention'] ?? '' : Settings::get( 'log_retention' );
		?>
		<div class="rvrt-log">
			<div class="rvrt-log-head">
				<p class="rvrt-log-totals">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: number of revisions, e.g. "3 revisions", 2: number of posts, e.g. "2 posts", 3: number of sweeps, e.g. "1 sweep", 4: how long entries are kept. */
							esc_html_x( '%1$s removed from %2$s in %3$s over the last %4$s.', 'log summary', 'revision-retention' ),
							/* translators: %s: number of revisions. */
							self::strong_count( _nx( '%s revision', '%s revisions', $totals['revisions'], 'log summary', 'revision-retention' ), $totals['revisions'] ),
							/* translators: %s: number of posts. */
							self::strong_count( _nx( '%s post', '%s posts', $totals['posts'], 'log summary', 'revision-retention' ), $totals['posts'] ),
							/* translators: %s: number of sweeps. */
							self::strong_count( _nx( '%s sweep', '%s sweeps', $totals['runs'], 'log summary', 'revision-retention' ), $totals['runs'] ),
							esc_html( mb_strtolower( Settings::log_retentions()[ (string) $setting ] ?? '' ) )
						),
						array( 'strong' => array() )
					);
					?>
				</p>
				<?php $this->render_filter( $source ); ?>
			</div>

			<?php $this->render_chart( self::CHART_DAYS ); ?>

			<?php if ( array() === $entries['rows'] ) : ?>
				<p class="rvrt-log-nothing"><?php echo esc_html_x( 'Nothing in the log matches this filter.', 'log empty state', 'revision-retention' ); ?></p>
			<?php else : ?>
				<?php $this->render_timeline( $entries['rows'] ); ?>
				<?php $this->render_pages( $page, $entries['total'], $source ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The last few days, weeks or months at a glance: the totals and the
	 * chart, nothing else.
	 *
	 * For the dashboard widget. The filter and the timeline are left to the
	 * Logs tab, which the widget links to. With more than one period, each
	 * gets its totals and chart, and a switch shows one at a time.
	 *
	 * @param array<string, int> $periods  How many bars each period gets, keyed by Log's PERIOD_ constants, in the switch's order.
	 * @param string             $current  The period shown first.
	 * @param string             $more_url Where the whole log is.
	 * @param string             $next     When the next sweep is expected, in a sentence, or empty.
	 * @param string             $stall    Why that sweep will not happen, in a sentence, or empty.
	 * @param string             $fix_url  Where the stall can be looked into, or empty for no link.
	 *
	 * @return void
	 */
	public function render_recent( array $periods, string $current, string $more_url, string $next = '', string $stall = '', string $fix_url = '' ): void {
		$labels = array(
			Log::PERIOD_DAY   => _x( 'Daily', 'chart period', 'revision-retention' ),
			Log::PERIOD_WEEK  => _x( 'Weekly', 'chart period', 'revision-retention' ),
			Log::PERIOD_MONTH => _x( 'Monthly', 'chart period', 'revision-retention' ),
		);
		?>
		<div class="rvrt-log rvrt-log-recent" data-period="<?php echo esc_attr( $current ); ?>">
			<?php // First, since nothing below it happens until it is sorted out. ?>
			<?php if ( '' !== $stall ) : ?>
				<p class="rvrt-log-stall">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<span>
						<?php echo esc_html( $stall ); ?>
						<?php if ( '' !== $fix_url ) : ?>
							<a href="<?php echo esc_url( $fix_url ); ?>"><?php echo esc_html_x( 'See the Schedule tab', 'dashboard widget link', 'revision-retention' ); ?></a>
						<?php endif; ?>
					</span>
				</p>
			<?php endif; ?>

			<?php if ( count( $periods ) > 1 ) : ?>
				<div class="rvrt-period-switch" role="group" aria-label="<?php echo esc_attr_x( 'Show the results', 'accessibility label', 'revision-retention' ); ?>">
					<?php foreach ( array_keys( $periods ) as $period ) : ?>
						<button type="button" class="rvrt-period-button" data-period="<?php echo esc_attr( $period ); ?>" aria-pressed="<?php echo $period === $current ? 'true' : 'false'; ?>">
							<?php echo esc_html( $labels[ $period ] ?? $period ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php foreach ( $periods as $period => $count ) : ?>
				<div class="rvrt-period" data-period="<?php echo esc_attr( $period ); ?>"<?php echo $period === $current ? '' : ' hidden'; ?>>
					<?php $this->render_period( $period, $count ); ?>
				</div>
			<?php endforeach; ?>

			<?php if ( '' !== $next ) : ?>
				<p class="rvrt-log-next">
					<span class="dashicons dashicons-clock" aria-hidden="true"></span>
					<span><?php echo esc_html( $next ); ?></span>
				</p>
			<?php endif; ?>

			<p class="rvrt-log-more">
				<a href="<?php echo esc_url( $more_url ); ?>">
					<?php echo esc_html_x( 'View the log', 'dashboard widget link', 'revision-retention' ); ?>
					<span class="rvrt-arrow" aria-hidden="true">&rarr;</span>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * The totals and the chart over the last few days, weeks or months.
	 *
	 * @param string $period One of Log's PERIOD_ constants, what each bar covers.
	 * @param int    $count  How many of them to cover, the current one included.
	 *
	 * @return void
	 */
	private function render_period( string $period, int $count ): void {
		$today  = new \DateTimeImmutable( 'today', wp_timezone() );
		$totals = Log::totals( $this->network, (int) Log::oldest( $period, $count )->diff( $today )->days + 1 );
		$span   = self::describe_span( $period, $count );
		?>
		<p class="rvrt-log-totals">
			<?php
			if ( 0 === $totals['runs'] ) {
				printf(
					/* translators: %s: how far back, e.g. "14 days" or "12 weeks". */
					esc_html_x( 'No revisions were removed in the last %s.', 'log summary', 'revision-retention' ),
					esc_html( $span )
				);
			} else {
				echo wp_kses(
					sprintf(
						/* translators: 1: number of revisions, e.g. "3 revisions", 2: number of posts, e.g. "2 posts", 3: number of sweeps, e.g. "1 sweep", 4: how far back, e.g. "14 days" or "12 weeks". */
						esc_html_x( '%1$s removed from %2$s in %3$s over the last %4$s.', 'log summary', 'revision-retention' ),
						/* translators: %s: number of revisions. */
						self::strong_count( _nx( '%s revision', '%s revisions', $totals['revisions'], 'log summary', 'revision-retention' ), $totals['revisions'] ),
						/* translators: %s: number of posts. */
						self::strong_count( _nx( '%s post', '%s posts', $totals['posts'], 'log summary', 'revision-retention' ), $totals['posts'] ),
						/* translators: %s: number of sweeps. */
						self::strong_count( _nx( '%s sweep', '%s sweeps', $totals['runs'], 'log summary', 'revision-retention' ), $totals['runs'] ),
						esc_html( $span )
					),
					array( 'strong' => array() )
				);
			}
			?>
		</p>

		<?php
		$this->render_chart( $count, $period );
	}

	/**
	 * A number of days, weeks or months, in words.
	 *
	 * @param string $period One of Log's PERIOD_ constants.
	 * @param int    $count  How many.
	 *
	 * @return string
	 */
	private static function describe_span( string $period, int $count ): string {
		$number = number_format_i18n( $count );

		return match ( $period ) {
			/* translators: %s: number of weeks. */
			Log::PERIOD_WEEK => sprintf( _nx( '%s week', '%s weeks', $count, 'log span', 'revision-retention' ), $number ),
			/* translators: %s: number of months. */
			Log::PERIOD_MONTH => sprintf( _nx( '%s month', '%s months', $count, 'log span', 'revision-retention' ), $number ),
			/* translators: %s: number of days. */
			default => sprintf( _nx( '%s day', '%s days', $count, 'log span', 'revision-retention' ), $number ),
		};
	}

	/**
	 * What to show before anything has been logged.
	 *
	 * @return void
	 */
	private function render_empty(): void {
		?>
		<div class="rvrt-log-empty">
			<span class="rvrt-log-empty-icon dashicons dashicons-backup" aria-hidden="true"></span>
			<p class="rvrt-log-empty-title"><?php echo esc_html_x( 'Nothing logged yet', 'log empty state', 'revision-retention' ); ?></p>
			<p class="description">
				<?php
				if ( $this->network ) {
					echo esc_html_x( 'Every sweep that removes revisions on a site that keeps a log will appear here: which site, who or what ran it, and how much it took away.', 'log empty state', 'revision-retention' );
				} else {
					echo esc_html(
						Log::enabled()
							? _x( 'Every sweep that removes revisions will appear here: who or what ran it, and how much it took away.', 'log empty state', 'revision-retention' )
							: _x( 'Switch the log on above, and every sweep that removes revisions will appear here: who or what ran it, and how much it took away.', 'log empty state', 'revision-retention' )
					);
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * The row of links narrowing the timeline to one kind of sweep.
	 *
	 * @param string $current Source filtered on, or empty for all.
	 *
	 * @return void
	 */
	private function render_filter( string $current ): void {
		$filters = array( '' => _x( 'All', 'log filter', 'revision-retention' ) ) + self::sources();

		printf( '<nav class="rvrt-log-filter" aria-label="%s">', esc_attr_x( 'Show sweeps run by', 'accessibility label', 'revision-retention' ) );

		foreach ( $filters as $source => $label ) {
			printf(
				'<a href="%s"%s>%s</a>',
				esc_url( '' === $source ? $this->base_url : add_query_arg( self::SOURCE_ARG, $source, $this->base_url ) ),
				$source === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * A bar per day, week or month, so a pattern shows at a glance.
	 *
	 * @param int    $span   How many bars the chart has, the current period included.
	 * @param string $period One of Log's PERIOD_ constants, what each bar covers.
	 *
	 * @return void
	 */
	private function render_chart( int $span, string $period = Log::PERIOD_DAY ): void {
		$days    = Log::per_period( $period, $span, $this->network );
		$highest = max( 1, ...array_values( $days ) );
		$summary = sprintf(
			/* translators: 1: number of revisions, 2: how far back, e.g. "14 days" or "12 weeks". */
			_nx( '%1$s revision removed in the last %2$s', '%1$s revisions removed in the last %2$s', array_sum( $days ), 'accessibility label', 'revision-retention' ),
			number_format_i18n( array_sum( $days ) ),
			self::describe_span( $period, $span )
		);
		?>
		<figure class="rvrt-chart">
			<?php // Each bar with something in it is a button, so its numbers can be had by pointer, by touch and by keyboard alike; the arrow keys move between them. A period with nothing removed has nothing to tell, so it is only drawn. ?>
			<div class="rvrt-chart-bars" role="group" aria-label="<?php echo esc_attr( $summary ); ?>">
				<?php
				$last = array_key_last( array_filter( $days ) );

				foreach ( $days as $day => $revisions ) :
					if ( 0 === $revisions ) {
						echo '<span class="rvrt-chart-bar is-empty" aria-hidden="true" inert></span>';
						continue;
					}

					$time = (int) strtotime( $day . ' 12:00:00' );
					$when = match ( $period ) {
						/* translators: %s: the date the week starts on. */
						Log::PERIOD_WEEK => sprintf( _x( 'Week of %s', 'chart tooltip', 'revision-retention' ), wp_date( get_option( 'date_format' ), $time, new \DateTimeZone( 'UTC' ) ) ),
						Log::PERIOD_MONTH => wp_date( _x( 'F Y', 'chart tooltip, month', 'revision-retention' ), $time, new \DateTimeZone( 'UTC' ) ),
						default => wp_date( get_option( 'date_format' ), $time, new \DateTimeZone( 'UTC' ) ),
					};
					$count = sprintf(
						/* translators: %s: number of revisions. */
						_nx( '%s revision removed', '%s revisions removed', $revisions, 'chart tooltip', 'revision-retention' ),
						number_format_i18n( $revisions )
					);
					?>
					<button
						type="button"
						class="rvrt-chart-bar"
						style="--rvrt-height: <?php echo esc_attr( (string) round( $revisions / $highest * 100, 1 ) ); ?>%"
						aria-label="<?php echo esc_attr( $when . ': ' . $count ); ?>"
						data-when="<?php echo esc_attr( (string) $when ); ?>"
						data-count="<?php echo esc_attr( $count ); ?>"
						tabindex="<?php echo $day === $last ? '0' : '-1'; ?>"
					></button>
				<?php endforeach; ?>
			</div>
			<div class="rvrt-chart-tip" aria-hidden="true" hidden>
				<strong class="rvrt-chart-tip-when"></strong>
				<span class="rvrt-chart-tip-count"></span>
			</div>
			<figcaption class="rvrt-chart-caption">
				<?php
				$back = number_format_i18n( $span - 1 );

				[ $oldest, $newest ] = match ( $period ) {
					Log::PERIOD_WEEK => array(
						/* translators: %s: number of weeks. */
						sprintf( _nx( '%s week ago', '%s weeks ago', $span - 1, 'chart axis', 'revision-retention' ), $back ),
						_x( 'This week', 'chart axis', 'revision-retention' ),
					),
					Log::PERIOD_MONTH => array(
						/* translators: %s: number of months. */
						sprintf( _nx( '%s month ago', '%s months ago', $span - 1, 'chart axis', 'revision-retention' ), $back ),
						_x( 'This month', 'chart axis', 'revision-retention' ),
					),
					default => array(
						/* translators: %s: number of days. */
						sprintf( _x( '%s days ago', 'chart axis', 'revision-retention' ), $back ),
						_x( 'Today', 'chart axis', 'revision-retention' ),
					),
				};
		?>
				<span><?php echo esc_html( $oldest ); ?></span>
				<span><?php echo esc_html( $newest ); ?></span>
			</figcaption>
		</figure>
		<?php
	}

	/**
	 * The entries, grouped under the day they finished on.
	 *
	 * @param array<int, array<string, mixed>> $rows Entries, newest first.
	 *
	 * @return void
	 */
	private function render_timeline( array $rows ): void {
		$days    = array();
		$largest = 1;

		foreach ( $rows as $row ) {
			$day            = (string) wp_date( 'Y-m-d', Log::timestamp( (string) $row['ended_gmt'] ) );
			$days[ $day ][] = $row;
			$largest        = max( $largest, (int) $row['revisions'] );
		}

		$index = 0;

		echo '<ol class="rvrt-timeline">';

		foreach ( $days as $day => $entries ) {
			?>
			<li class="rvrt-day">
				<h3 class="rvrt-day-label"><?php echo esc_html( self::describe_day( $day ) ); ?></h3>
				<ol class="rvrt-day-entries">
					<?php
					foreach ( $entries as $entry ) {
						$this->render_entry( $entry, $largest, $index );
						++$index;
					}
					?>
				</ol>
			</li>
			<?php
		}

		echo '</ol>';
	}

	/**
	 * One sweep on the timeline.
	 *
	 * @param array<string, mixed> $entry   The entry.
	 * @param int                  $largest Most revisions any entry on this page removed, to scale the meter by.
	 * @param int                  $index   Position on the page, which staggers the entrance.
	 *
	 * @return void
	 */
	private function render_entry( array $entry, int $largest, int $index ): void {
		$source    = (string) $entry['source'];
		$user_id   = (int) $entry['user_id'];
		$revisions = (int) $entry['revisions'];
		$posts     = (int) $entry['posts'];
		$started   = Log::timestamp( (string) $entry['started_gmt'] );
		$ended     = Log::timestamp( (string) $entry['ended_gmt'] );
		$status    = Log::status( $entry );
		$types     = json_decode( (string) $entry['post_types'], true );
		$types     = is_array( $types ) ? $types : array();
		?>
		<li class="rvrt-entry rvrt-entry-<?php echo esc_attr( $source ); ?>" style="--rvrt-i: <?php echo esc_attr( (string) min( $index, 12 ) ); ?>">
			<?php $this->render_marker( $source, $user_id ); ?>

			<article class="rvrt-card">
				<header class="rvrt-card-head">
					<span class="rvrt-card-who"><?php $this->render_actor( $source, $user_id ); ?></span>
					<?php
					if ( $this->network ) {
						$this->render_site( (int) ( $entry['blog_id'] ?? 0 ) );
					}
					?>
					<span class="rvrt-pill rvrt-pill-<?php echo esc_attr( $source ); ?>"><?php echo esc_html( self::sources()[ $source ] ?? $source ); ?></span>
					<time class="rvrt-card-time" datetime="<?php echo esc_attr( gmdate( 'c', $ended ) ); ?>" title="<?php echo esc_attr( (string) wp_date( get_option( 'date_format' ) . ' ' . Settings::time_format(), $ended ) ); ?>">
						<?php echo esc_html( (string) wp_date( Settings::time_format(), $ended ) ); ?>
					</time>
				</header>

				<p class="rvrt-card-what">
					<span class="rvrt-card-count"><?php echo esc_html( number_format_i18n( $revisions ) ); ?></span>
					<span class="rvrt-card-unit">
						<?php
						printf(
							/* translators: %s: number of posts. */
							esc_html( _nx( 'revisions removed from %s post', 'revisions removed from %s posts', $posts, 'log entry', 'revision-retention' ) ),
							esc_html( number_format_i18n( $posts ) )
						);
						?>
					</span>
				</p>

				<span class="rvrt-meter" aria-hidden="true"><span style="--rvrt-share: <?php echo esc_attr( (string) max( 2, round( $revisions / $largest * 100, 1 ) ) ); ?>%"></span></span>

				<?php if ( array() !== $types ) : ?>
					<ul class="rvrt-types">
						<?php foreach ( $types as $type => $count ) : ?>
							<li><?php echo esc_html( '' === (string) $type ? _x( 'Other', 'post type fallback', 'revision-retention' ) : (string) $type ); ?> <b><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></b></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<p class="rvrt-status rvrt-status-<?php echo esc_attr( $status ); ?>">
					<span class="rvrt-status-dot" aria-hidden="true"></span>
					<?php echo esc_html( self::describe_status( $status, $started, $ended ) ); ?>
				</p>
			</article>
		</li>
		<?php
	}

	/**
	 * The avatar or icon on the line.
	 *
	 * @param string $source  Where the sweep came from.
	 * @param int    $user_id Who ran it, 0 for nobody.
	 *
	 * @return void
	 */
	private function render_marker( string $source, int $user_id ): void {
		$avatar = $user_id > 0 ? get_avatar_url( $user_id, array( 'size' => 72 ) ) : false;

		if ( is_string( $avatar ) && '' !== $avatar ) {
			printf(
				'<span class="rvrt-marker rvrt-marker-avatar" aria-hidden="true"><img src="%s" alt="" width="36" height="36" loading="lazy" /></span>',
				esc_url( $avatar )
			);

			return;
		}

		printf(
			'<span class="rvrt-marker" aria-hidden="true"><span class="dashicons dashicons-%s"></span></span>',
			esc_attr( Log::SOURCE_CLI === $source ? 'editor-code' : ( Log::SOURCE_CRON === $source ? 'clock' : 'admin-users' ) )
		);
	}

	/**
	 * Name whoever ran the sweep, linked to their profile where allowed.
	 *
	 * @param string $source  Where the sweep came from.
	 * @param int    $user_id Who ran it, 0 for nobody.
	 *
	 * @return void
	 */
	private function render_actor( string $source, int $user_id ): void {
		if ( 0 === $user_id ) {
			echo esc_html(
				Log::SOURCE_CRON === $source
					? _x( 'The schedule', 'log actor', 'revision-retention' )
					: _x( 'Somebody on the server', 'log actor', 'revision-retention' )
			);

			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof \WP_User ) {
			printf(
				/* translators: %s: user ID. */
				esc_html_x( 'A deleted user (#%s)', 'log actor', 'revision-retention' ),
				esc_html( (string) $user_id )
			);

			return;
		}

		if ( current_user_can( 'edit_user', $user_id ) ) {
			printf( '<a href="%s">%s</a>', esc_url( get_edit_user_link( $user_id ) ), esc_html( $user->display_name ) );

			return;
		}

		echo esc_html( $user->display_name );
	}

	/**
	 * Name the site a sweep ran on, linked to that site's own log.
	 *
	 * @param int $site_id Site the entry was logged on.
	 *
	 * @return void
	 */
	private function render_site( int $site_id ): void {
		$site = get_site( $site_id );

		if ( ! $site instanceof \WP_Site ) {
			printf(
				'<span class="rvrt-card-site">%s</span>',
				/* translators: %s: site ID. */
				esc_html( sprintf( _x( 'A deleted site (#%s)', 'log site', 'revision-retention' ), $site_id ) )
			);

			return;
		}

		printf(
			'<a class="rvrt-card-site" href="%s"><span class="dashicons dashicons-admin-multisite" aria-hidden="true"></span>%s</a>',
			esc_url( get_admin_url( $site_id, 'options-general.php?page=' . Settings_Page::PAGE_SLUG . '&rvrt-tab=logs' ) ),
			esc_html( '' !== (string) $site->blogname ? (string) $site->blogname : $site->domain . $site->path )
		);
	}

	/**
	 * Links to the next and previous pages.
	 *
	 * @param int    $page   Page being shown.
	 * @param int    $total  Entries matching the filter.
	 * @param string $source Source filtered on, or empty.
	 *
	 * @return void
	 */
	private function render_pages( int $page, int $total, string $source ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$url = '' === $source ? $this->base_url : add_query_arg( self::SOURCE_ARG, $source, $this->base_url );
		?>
		<nav class="rvrt-log-pages" aria-label="<?php echo esc_attr_x( 'Log pages', 'accessibility label', 'revision-retention' ); ?>">
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( self::PAGE_ARG, $page - 1, $url ) ); ?>"><?php echo esc_html_x( 'Newer', 'log pagination', 'revision-retention' ); ?></a>
			<?php endif; ?>
			<span class="rvrt-log-page-of">
				<?php
				printf(
					/* translators: 1: current page, 2: number of pages. */
					esc_html_x( 'Page %1$s of %2$s', 'log pagination', 'revision-retention' ),
					esc_html( number_format_i18n( $page ) ),
					esc_html( number_format_i18n( $pages ) )
				);
				?>
			</span>
			<?php if ( $page < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( self::PAGE_ARG, $page + 1, $url ) ); ?>"><?php echo esc_html_x( 'Older', 'log pagination', 'revision-retention' ); ?></a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * Where a sweep can come from, labelled.
	 *
	 * @return array<string, string>
	 */
	private static function sources(): array {
		return array(
			Log::SOURCE_CRON   => _x( 'Scheduled', 'log source', 'revision-retention' ),
			Log::SOURCE_SCREEN => _x( 'Run now', 'log source', 'revision-retention' ),
			Log::SOURCE_CLI    => _x( 'WP-CLI', 'log source', 'revision-retention' ),
		);
	}

	/**
	 * A count in its own plural, with the number in bold, ready to print.
	 *
	 * @param string $template Translated `%s` template, already in the right plural.
	 * @param int    $value    The count.
	 *
	 * @return string Escaped HTML.
	 */
	private static function strong_count( string $template, int $value ): string {
		return sprintf( esc_html( $template ), '<strong>' . esc_html( number_format_i18n( $value ) ) . '</strong>' );
	}

	/**
	 * Put a day into the words people use for it.
	 *
	 * @param string $day Date in `Y-m-d`, site timezone.
	 *
	 * @return string
	 */
	private static function describe_day( string $day ): string {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );

		if ( $today->format( 'Y-m-d' ) === $day ) {
			return _x( 'Today', 'log day', 'revision-retention' );
		}

		if ( $today->modify( '-1 day' )->format( 'Y-m-d' ) === $day ) {
			return _x( 'Yesterday', 'log day', 'revision-retention' );
		}

		$date   = new \DateTimeImmutable( $day . ' 12:00:00', wp_timezone() );
		$format = $date->format( 'Y' ) === $today->format( 'Y' )
			? _x( 'l j F', 'log day heading, this year', 'revision-retention' )
			: _x( 'l j F Y', 'log day heading, another year', 'revision-retention' );

		return (string) wp_date( $format, $date->getTimestamp() );
	}

	/**
	 * Say where a sweep got to, and how long it took.
	 *
	 * @param string $status  One of `finished`, `running` or `stopped`.
	 * @param int    $started When the first batch ran.
	 * @param int    $ended   When the last batch ran.
	 *
	 * @return string
	 */
	private static function describe_status( string $status, int $started, int $ended ): string {
		$took = $ended - $started < MINUTE_IN_SECONDS
			? _x( 'under a minute', 'sweep duration', 'revision-retention' )
			: human_time_diff( $started, $ended );

		return match ( $status ) {
			/* translators: %s: how long the sweep took. */
			'finished' => sprintf( _x( 'Completed in %s', 'log status', 'revision-retention' ), $took ),
			'running' => _x( 'In progress', 'log status', 'revision-retention' ),
			/* translators: %s: how long the sweep ran for. */
			default => sprintf( _x( 'Stopped part way after %s; the next sweep continues', 'log status', 'revision-retention' ), $took ),
		};
	}
}
