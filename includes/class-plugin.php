<?php
/**
 * Plugin bootstrap.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention\Includes;

use Acato\RevisionRetention\Admin\Assets;
use Acato\RevisionRetention\Admin\DashboardWidget;
use Acato\RevisionRetention\Admin\RatingNotice;
use Acato\RevisionRetention\Admin\SettingsPage;
use Acato\RevisionRetention\Admin\Tour;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin features into WordPress.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
final class Plugin extends Singleton {

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
				[
					'fields' => 'ids',
					'number' => 0,
				]
			)
		);
		$network = (array) get_site_option( 'active_sitewide_plugins', [] );

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
				static fn( int $id ): bool => in_array( $basename, (array) get_blog_option( $id, 'active_plugins', [] ), true )
			)
		);
	}

	/**
	 * Register the activation hooks, then every feature of the plugin.
	 *
	 * @return void
	 */
	protected function init(): void {
		register_activation_hook( RVRT_PLUGIN_FILE, [ Requirements::class, 'block_activation' ] );
		register_activation_hook( RVRT_PLUGIN_FILE, [ Upgrade::class, 'on_activation' ] );
		register_activation_hook( RVRT_PLUGIN_FILE, [ Scheduler::class, 'on_activation' ] );
		register_activation_hook( RVRT_PLUGIN_FILE, [ RatingNotice::class, 'remember_install' ] );
		register_activation_hook( RVRT_PLUGIN_FILE, [ self::class, 'forget_sites' ] );
		register_deactivation_hook( RVRT_PLUGIN_FILE, [ Scheduler::class, 'on_deactivation' ] );
		register_deactivation_hook( RVRT_PLUGIN_FILE, [ Log::class, 'on_deactivation' ] );
		register_deactivation_hook( RVRT_PLUGIN_FILE, [ self::class, 'forget_sites' ] );

		// A WordPress old enough to miss the revision APIs this plugin builds
		// on would break in ways an administrator cannot act on, so it stops
		// before booting.
		if ( ! Requirements::are_met() ) {
			Requirements::instance();

			return;
		}

		$this->boot();
	}

	/**
	 * Register every feature of the plugin.
	 *
	 * @return void
	 */
	private function boot(): void {
		Upgrade::instance();
		PostTypes::instance();
		Limits::instance();
		Scheduler::instance();
		Log::instance();
		Assets::instance();
		SettingsPage::instance();
		RatingNotice::instance();
		DashboardWidget::instance();
		Tour::instance();
		Abilities::instance();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register();
		}
	}
}
