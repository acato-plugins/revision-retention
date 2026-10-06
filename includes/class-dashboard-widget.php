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
 * Network tab.
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
	 * Days the widget covers.
	 *
	 * @var int
	 */
	private const DAYS = 14;

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
			wp_enqueue_style( 'rvrt-settings', $style, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The hash in the built file name is the version.
		}

		$script = Assets::url( 'src/widget.js' );

		if ( null !== $script && self::has_network_tab() ) {
			wp_enqueue_script( 'rvrt-widget', $script, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The hash in the built file name is the version.
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

		( new Log_View( $logs, $network ) )->render_recent( self::DAYS, $logs );
	}

	/**
	 * Whether this is a site's Dashboard seen by a network administrator,
	 * who gets the whole network next to the site.
	 *
	 * @return bool
	 */
	private static function has_network_tab(): bool {
		return is_multisite() && ! is_network_admin() && current_user_can( Settings::capability( true ) );
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
