<?php
/**
 * Asks for a rating once the plugin has had time to prove itself.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * A notice on the Dashboard, a month after activation, asking for a rating.
 *
 * A month is long enough for a scheduled sweep or two to have run, so the
 * question comes from somebody who has seen what the plugin does. It only
 * appears on the Dashboard, never on the screens people come to work on,
 * and only to those who manage the plugin. The answer is kept per user, so
 * one administrator saying no does not speak for another, and on multisite
 * a user's answer holds on the network and every site alike.
 */
class Rating_Notice {

	/**
	 * Network wide option holding when the plugin was first activated.
	 *
	 * @var string
	 */
	public const INSTALLED_OPTION = 'rvrt_installed';

	/**
	 * User meta holding the answer: `done`, or when to ask again.
	 *
	 * @var string
	 */
	public const USER_META = 'rvrt_rating_notice';

	/**
	 * Where people rate the plugin: the review form itself, rather than the
	 * plugin page a click away from it.
	 *
	 * @var string
	 */
	public const RATE_URL = 'https://wordpress.org/support/plugin/revision-retention/reviews/#new-post';

	/**
	 * Action the notice's links post to.
	 *
	 * @var string
	 */
	private const ACTION = 'rvrt_rating';

	/**
	 * How long after activation, and after "Maybe later", before asking.
	 *
	 * @var int
	 */
	private const WAIT = MONTH_IN_SECONDS;

	/**
	 * Hook the notice into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( self::class, 'remember_install' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Note when the plugin was activated, unless that is already known.
	 *
	 * Runs on activation, and on any admin screen for an install that was
	 * activated before the plugin kept track, which then waits a month from
	 * the update like everybody else.
	 *
	 * @return void
	 */
	public static function remember_install(): void {
		if ( false === get_site_option( self::INSTALLED_OPTION ) ) {
			update_site_option( self::INSTALLED_OPTION, time() );
		}
	}

	/**
	 * Show the notice, when this is the place and the time for it.
	 *
	 * @return void
	 */
	public function render(): void {
		$screen = get_current_screen();

		if ( null === $screen || ! in_array( $screen->id, array( 'dashboard', 'dashboard-network' ), true ) ) {
			return;
		}

		if ( ! self::is_due( 'dashboard-network' === $screen->id ) ) {
			return;
		}

		$link = static fn( string $choice ): string => wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'choice' => $choice,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
		?>
		<div class="notice notice-info rvrt-rating">
			<p>
				<?php echo esc_html_x( 'You have been using Revision Retention for a month now. If it keeps your database tidy, would you rate it on WordPress.org? A rating helps other sites find it, and takes a minute.', 'rating notice', 'revision-retention' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $link( 'rate' ) ); ?>" target="_blank" rel="noopener">
					<?php echo esc_html_x( 'Rate Revision Retention', 'rating notice', 'revision-retention' ); ?>
					<span class="screen-reader-text"><?php echo esc_html_x( '(opens in a new tab)', 'accessibility label', 'revision-retention' ); ?></span>
				</a>
				<a class="button" href="<?php echo esc_url( $link( 'later' ) ); ?>">
					<?php echo esc_html_x( 'Maybe later', 'rating notice', 'revision-retention' ); ?>
				</a>
				<a class="button-link" href="<?php echo esc_url( $link( 'never' ) ); ?>">
					<?php echo esc_html_x( 'Don\'t ask again', 'rating notice', 'revision-retention' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Store the answer, then go where it leads.
	 *
	 * Rating goes on to WordPress.org; the other two come back to the screen
	 * they were given on, without the notice.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );

		$choice  = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::USER_META, 'later' === $choice ? (string) ( time() + self::WAIT ) : 'done' );
		}

		if ( 'rate' === $choice ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- A fixed address of our own on WordPress.org, never one taken from the request.
			wp_redirect( self::RATE_URL );
			exit;
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Whether the current user should be asked now.
	 *
	 * @param bool $network Whether this is the network Dashboard.
	 *
	 * @return bool
	 */
	public static function is_due( bool $network ): bool {
		if ( ! current_user_can( Settings::capability( $network ) ) ) {
			return false;
		}

		$installed = (int) get_site_option( self::INSTALLED_OPTION, 0 );

		if ( $installed < 1 || time() < $installed + self::WAIT ) {
			return false;
		}

		$answer = (string) get_user_meta( get_current_user_id(), self::USER_META, true );

		return '' === $answer || ( 'done' !== $answer && time() >= (int) $answer );
	}

	/**
	 * Forget the install time and every user's answer.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_site_option( self::INSTALLED_OPTION );
		delete_metadata( 'user', 0, self::USER_META, '', true );
	}
}
