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
	 * Network option holding the sites the plugin is active on, when it is
	 * activated per site.
	 *
	 * @var string
	 */
	public const SITES_OPTION = 'rvrt_active_sites';

	/**
	 * The sites on the network the plugin is active on.
	 *
	 * Activated on the network, that is every site. Activated per site, the
	 * network screen still sets the defaults, but a sweep from there must
	 * leave alone the sites that never turned the plugin on: nothing there
	 * would handle the event it books, and it should not delete revisions or
	 * create a log table on a site that did not ask for it.
	 *
	 * Finding those sites reads every site's active plugins, which a network
	 * sweep would otherwise do again for each batch. The list is worked out
	 * when asked for fresh, at the start of a sweep, and kept until then or
	 * until the plugin is activated or deactivated somewhere. Sites deleted
	 * since drop out, since the network's own list is read each time.
	 *
	 * @param bool $fresh Work the list out again rather than use the kept one.
	 *
	 * @return array<int, int> Site IDs, in the order get_sites() gives them.
	 */
	public static function sites( bool $fresh = false ): array {
		$ids     = array_map(
			'intval',
			(array) get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );

		if ( isset( $network[ plugin_basename( RVRT_PLUGIN_FILE ) ] ) ) {
			return $ids;
		}

		$kept = $fresh ? false : get_site_option( self::SITES_OPTION, false );

		if ( ! is_array( $kept ) ) {
			$kept = self::find_active( $ids );

			update_site_option( self::SITES_OPTION, $kept );
		}

		return array_values( array_intersect( $ids, array_map( 'intval', $kept ) ) );
	}

	/**
	 * Forget which sites the plugin is active on, so the next sweep looks again.
	 *
	 * @return void
	 */
	public static function forget_sites(): void {
		if ( is_multisite() ) {
			delete_site_option( self::SITES_OPTION );
		}
	}

	/**
	 * The sites among these that have the plugin in their active plugins.
	 *
	 * @param array<int, int> $ids Site IDs.
	 *
	 * @return array<int, int>
	 */
	private static function find_active( array $ids ): array {
		$basename = plugin_basename( RVRT_PLUGIN_FILE );

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
