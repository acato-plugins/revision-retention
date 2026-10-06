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
 *
 * @author Paul van Impelen <paul@acato.nl>
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
	 * The sites on the network the plugin is active on.
	 *
	 * Activated on the network, that is every site. Activated per site, the
	 * network screen still sets the defaults, but a sweep from there must
	 * leave alone the sites that never turned the plugin on: nothing there
	 * would handle the event it books, and it should not delete revisions or
	 * create a log table on a site that did not ask for it.
	 *
	 * @return array<int, int> Site IDs, in the order get_sites() gives them.
	 */
	public static function sites(): array {
		$ids      = array_map(
			'intval',
			(array) get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
		$basename = plugin_basename( RVRT_PLUGIN_FILE );
		$network  = (array) get_site_option( 'active_sitewide_plugins', array() );

		if ( isset( $network[ $basename ] ) ) {
			return $ids;
		}

		return array_values(
			array_filter(
				$ids,
				static fn( int $id ): bool => in_array( $basename, (array) get_blog_option( $id, 'active_plugins', array() ), true )
			)
		);
	}

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
		( new Assets() )->register();
		( new Settings_Page() )->register();
		( new Rating_Notice() )->register();
		( new Dashboard_Widget() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI::register();
		}
	}
}
