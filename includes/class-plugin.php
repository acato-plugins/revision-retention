<?php
/**
 * Plugin bootstrap.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin features into WordPress.
 */
final class Plugin {

	/**
	 * The single plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Retrieve the single plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor, use Plugin::instance() instead.
	 */
	private function __construct() {}

	/**
	 * Register every feature of the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		( new Post_Types() )->register();
		( new Limits() )->register();
		( new Scheduler() )->register();
		( new Log() )->register();
		( new Settings_Page() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI::register();
		}
	}
}
