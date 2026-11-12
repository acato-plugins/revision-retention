<?php
/**
 * A short walkthrough of the settings screen, shown again when it grows.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention\Admin;

use Acato\RevisionRetention\Includes\Settings;
use Acato\RevisionRetention\Includes\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Walks those who manage the plugin through the settings screen, on
 * WordPress's own admin pointers.
 *
 * Every step says in which version it was added, and the newest of those is
 * the walkthrough's version. Each user's last completed or skipped version is
 * kept in their user meta. Somebody who never took the walkthrough gets all of
 * it; after an update that adds steps, somebody who took an older one gets
 * only the new steps, so it reads as what changed. Adding a step with a newer
 * `since` is all a release has to do: there is no version to bump next to it.
 *
 * Until then a card beside the Settings menu says there is something to
 * see, on every admin screen but the plugin's own, until it is taken or put
 * off with "Not now". It has the plugin's own look, the banner's dark glow
 * and its Wapuu, so it reads as this plugin's and not as one of core's.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
final class Tour extends Singleton {

	/**
	 * User meta holding the walkthrough version this user last completed or skipped.
	 *
	 * @var string
	 */
	public const USER_META = 'rvrt_tour';

	/**
	 * AJAX action the script reports a finished walkthrough to.
	 *
	 * @var string
	 */
	private const ACTION = 'rvrt_tour_seen';

	/**
	 * Whether the walkthrough was loaded on this request's screen.
	 *
	 * @var bool
	 */
	private bool $on_screen = false;

	/**
	 * Hook the walkthrough into WordPress.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'menu_pointer' ] );
		add_action( 'admin_footer', [ $this, 'render_templates' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'mark_seen' ] );
	}

	/**
	 * Every step, oldest first.
	 *
	 * A step whose target is not on the screen is left out, which is how one
	 * list serves a site, a site that may only follow the network, and the
	 * network screen. `target` takes a selector list; the first match is used.
	 * `preview` shows a piece of the screen in the pointer itself: `log` is
	 * the newest entry of the log, or an example while there is none.
	 *
	 * @return array<int, array{since: string, tab: string, target: string, title: string, text: string, preview?: string, edge?: string, align?: string}>
	 */
	public static function steps(): array {
		return [
			[
				'since'  => '1.7.0',
				'tab'    => '',
				'target' => '.rvrt-stats',
				'title'  => _x( 'At a glance', 'walkthrough step title', 'revision-retention' ),
				'text'   => _x( 'How many revisions this site stores, how many of them every post always keeps, from what age the rest are removed, and how long until the next sweep.', 'walkthrough step', 'revision-retention' ),
			],
			[
				'since'  => '1.7.0',
				'tab'    => 'policy',
				'target' => '#rvrt-keep, .rvrt-summary thead',
				'title'  => _x( 'Your policy', 'walkthrough step title', 'revision-retention' ),
				'text'   => _x( 'Keep the newest few revisions of every post, and remove the ones past an age. The newest ones are kept however old they get.', 'walkthrough step', 'revision-retention' ),
			],
			[
				'since'  => '1.7.0',
				'tab'    => 'post-types',
				'target' => '.rvrt-post-types thead',
				'title'  => _x( 'Per post type', 'walkthrough step title', 'revision-retention' ),
				'text'   => _x( 'Give a post type a policy of its own, or switch revisions on for one that has none.', 'walkthrough step', 'revision-retention' ),
			],
			[
				'since'  => '1.7.0',
				'tab'    => 'schedule',
				'target' => '#rvrt-cron-interval',
				'title'  => _x( 'Schedule', 'walkthrough step title', 'revision-retention' ),
				'text'   => _x( 'How often the sweep runs in the background, on which days and at what time. A warning shows here when WP-Cron is not running on the site.', 'walkthrough step', 'revision-retention' ),
			],
			[
				'since'  => '1.7.0',
				'tab'    => 'sites',
				'target' => '#rvrt-allow-site-override',
				'title'  => _x( 'Sites', 'walkthrough step title', 'revision-retention' ),
				'text'   => _x( 'Whether the sites of the network may set a policy of their own, or all follow the one set here.', 'walkthrough step', 'revision-retention' ),
			],
			[
				'since'  => '1.7.0',
				'tab'    => 'sweep',
				'target' => '.rvrt-sweep-buttons',
				'title'  => _x( 'Sweep now', 'walkthrough step title', 'revision-retention' ),
				'text'   => _x( 'Preview lists what the policy would remove, without deleting anything. Run now removes it straight away, in batches you can stop.', 'walkthrough step', 'revision-retention' ),
			],
			[
				'since'   => '1.7.0',
				'tab'     => 'logs',
				'target'  => '.rvrt-log-head, .rvrt-log-empty-title',
				'title'   => _x( 'Logs', 'walkthrough step title', 'revision-retention' ),
				'text'    => _x( 'Every sweep that removed revisions, who or what ran it, and how many went, with a bar per day.', 'walkthrough step', 'revision-retention' ),
				'preview' => 'log',
			],
			[
				'since'  => '1.7.0',
				'tab'    => 'advanced',
				'target' => '#rvrt-widget-roles',
				'title'  => _x( 'Dashboard widget', 'walkthrough step title', 'revision-retention' ),
				'text'   => _x( 'Choose who sees the recent sweeps on their Dashboard, and may read the log.', 'walkthrough step', 'revision-retention' ),
			],
		];
	}

	/**
	 * The walkthrough's version: the newest step's.
	 *
	 * @return string
	 */
	public static function version(): string {
		return array_reduce(
			array_column( self::steps(), 'since' ),
			static fn( string $newest, string $since ): string => version_compare( $since, $newest, '>' ) ? $since : $newest,
			'0'
		);
	}

	/**
	 * The version this user last completed or skipped, or '' when they never did.
	 *
	 * @return string
	 */
	public static function seen(): string {
		return (string) get_user_meta( get_current_user_id(), self::USER_META, true );
	}

	/**
	 * Whether the current user has steps they have not seen yet.
	 *
	 * @param bool $network Whether the network admin is meant.
	 *
	 * @return bool
	 */
	public static function is_pending( bool $network ): bool {
		if ( ! current_user_can( Settings::capability( $network ) ) ) {
			return false;
		}

		return version_compare( '' === self::seen() ? '0' : self::seen(), self::version(), '<' );
	}

	/**
	 * The steps added after a version, or every step when nothing was seen.
	 *
	 * @param string $seen Version last seen, '' for none.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function steps_since( string $seen ): array {
		if ( '' === $seen ) {
			return self::steps();
		}

		return array_values(
			array_filter(
				self::steps(),
				static fn( array $step ): bool => version_compare( $step['since'], $seen, '>' )
			)
		);
	}

	/**
	 * Load the walkthrough on the settings screen, for those who manage the plugin.
	 *
	 * @param string $hook_suffix Screen the hook fired for.
	 *
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		$network = is_network_admin();

		if ( ! is_string( $hook_suffix ) || ! str_contains( $hook_suffix, SettingsPage::PAGE_SLUG ) || ! current_user_can( Settings::capability( $network ) ) ) {
			return;
		}

		$script = Assets::url( 'src/tour.js' );

		if ( null === $script ) {
			return;
		}

		$pending = self::is_pending( $network );

		$this->on_screen = true;
		self::enqueue_style();
		wp_enqueue_script( 'rvrt-tour', $script, [ 'rvrt-settings', 'wp-pointer', 'wp-a11y', 'wp-i18n' ], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The hash in the built file name, or the ver on a source, is the version.
		wp_set_script_translations( 'rvrt-tour', 'revision-retention' );
		wp_add_inline_script(
			'rvrt-tour',
			'window.rvrtTour = ' . wp_json_encode(
				[
					'steps'   => self::steps(),
					'pending' => $pending ? self::steps_since( self::seen() ) : [],
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => self::ACTION,
					'nonce'   => wp_create_nonce( self::ACTION ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Core's name for this version's menu pointer, dismissed per user through
	 * core's own `dismiss-wp-pointer`.
	 *
	 * @return string
	 */
	private static function pointer_name(): string {
		return 'rvrt_tour_' . str_replace( '.', '_', self::version() );
	}

	/**
	 * The card beside the Settings menu, until the walkthrough is taken or put off.
	 *
	 * @param string $hook_suffix Screen the hook fired for.
	 *
	 * @return void
	 */
	public function menu_pointer( $hook_suffix ): void {
		$network = is_network_admin();

		if ( ( is_string( $hook_suffix ) && str_contains( $hook_suffix, SettingsPage::PAGE_SLUG ) ) || ! self::is_pending( $network ) ) {
			return;
		}

		$dismissed = explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) );

		if ( in_array( self::pointer_name(), $dismissed, true ) ) {
			return;
		}

		$script = Assets::url( 'src/menu-pointer.js' );

		if ( null === $script ) {
			return;
		}

		$first = '' === self::seen();

		self::enqueue_style( false );
		wp_enqueue_script( 'rvrt-menu-pointer', $script, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The hash in the built file name, or the ver on a source, is the version.
		wp_add_inline_script(
			'rvrt-menu-pointer',
			'window.rvrtMenuPointer = ' . wp_json_encode(
				[
					'name'     => self::pointer_name(),
					'title'    => _x( 'Revision Retention', 'admin menu and page title', 'revision-retention' ),
					'text'     => $first
						? _x( 'Keep the revisions you need and sweep out the rest. A short walkthrough shows you around the settings.', 'walkthrough pointer', 'revision-retention' )
						: _x( 'Revision Retention has changed. A short walkthrough shows what is new.', 'walkthrough pointer', 'revision-retention' ),
					'link'     => SettingsPage::page_url( $network ),
					'linkText' => _x( 'Take the walkthrough', 'walkthrough pointer', 'revision-retention' ),
					'dismiss'  => _x( 'Not now', 'walkthrough pointer', 'revision-retention' ),
					'wapuu'    => plugin_dir_url( RVRT_PLUGIN_FILE ) . 'src/images/wapuu.png',
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * The example log entry the Logs step shows while the log is empty.
	 *
	 * Rendered by the log itself, so it looks exactly like a real entry, and
	 * kept in a template, so it is only read by whoever opens the step.
	 *
	 * @return void
	 */
	public function render_templates(): void {
		if ( ! $this->on_screen ) {
			return;
		}

		$network = is_network_admin();

		echo '<template id="rvrt-tour-log-example">';
		( new LogView( SettingsPage::page_url( $network ), $network ) )->render_example();
		echo '</template>';
	}

	/**
	 * The pointers' own styles.
	 *
	 * @param bool $core Whether core's pointer is used too, which the walkthrough's steps are.
	 *
	 * @return void
	 */
	private static function enqueue_style( bool $core = true ): void {
		$style = Assets::url( 'src/pointer.css' );

		if ( $core ) {
			wp_enqueue_style( 'wp-pointer' );
		}

		if ( null !== $style ) {
			wp_enqueue_style( 'rvrt-pointer', $style, $core ? [ 'wp-pointer' ] : [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The hash in the built file name, or the ver on a source, is the version.
		}
	}

	/**
	 * Store that the current user completed or skipped this version.
	 *
	 * @return void
	 */
	public function mark_seen(): void {
		// A super admin, who takes it on the network screen, holds this
		// capability on every site.
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_send_json_error( null, 403 );
		}

		check_ajax_referer( self::ACTION );

		update_user_meta( get_current_user_id(), self::USER_META, self::version() );

		wp_send_json_success( [ 'version' => self::version() ] );
	}

	/**
	 * Forget which version every user saw.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_metadata( 'user', 0, self::USER_META, '', true );
	}
}
