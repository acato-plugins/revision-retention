<?php
/**
 * Minimum WordPress version guard.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the plugin from running on a WordPress version it cannot support.
 */
class Requirements {

	/**
	 * Lowest WordPress version this plugin supports.
	 *
	 * @var string
	 */
	public const MIN_WP_VERSION = '6.7';

	/**
	 * Whether the current WordPress version is new enough.
	 *
	 * @return bool
	 */
	public static function are_met(): bool {
		return version_compare( self::current_version(), self::MIN_WP_VERSION, '>=' );
	}

	/**
	 * Stop activation on an unsupported WordPress version.
	 *
	 * Runs as the activation hook, so the plugin never becomes active in the
	 * first place and the administrator gets told why.
	 *
	 * @return void
	 */
	public static function block_activation(): void {
		if ( self::are_met() ) {
			return;
		}

		wp_die(
			esc_html( self::message() ),
			esc_html__( 'Plugin could not be activated', 'revision-retention' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * Hook the guard into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'deactivate' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Deactivate the plugin when WordPress is too old.
	 *
	 * This covers an install that was downgraded after the plugin was already
	 * active. The notice below is shown in this same request; afterwards the
	 * plugin is off and no longer loads.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$basename = plugin_basename( RVRT_PLUGIN_FILE );

		deactivate_plugins( $basename, true, is_plugin_active_for_network( $basename ) );
	}

	/**
	 * Tell the administrator what happened and what to do about it.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( self::message() )
		);
	}

	/**
	 * The message shown on activation and in the admin notice.
	 *
	 * @return string
	 */
	private static function message(): string {
		return sprintf(
			/* translators: 1: required WordPress version, 2: WordPress version currently running. */
			__( 'Revision Retention requires WordPress %1$s or newer, but this site runs WordPress %2$s. The plugin has been deactivated. Please update WordPress and activate the plugin again.', 'revision-retention' ),
			self::MIN_WP_VERSION,
			self::current_version()
		);
	}

	/**
	 * The WordPress version currently running.
	 *
	 * @return string
	 */
	private static function current_version(): string {
		return (string) get_bloginfo( 'version' );
	}
}
