<?php
/**
 * Plugin Name:       Revision Retention
 * Plugin URI:        https://github.com/acato-plugins/revision-retention
 * Description:       Give post revisions a retention policy: keep the newest few, drop the ones older than a threshold, per post type, on a schedule or from WP-CLI.
 * Version:           1.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Acato
 * Author URI:        https://acato.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       revision-retention
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'RVRT_VERSION' ) ) {
	define( 'RVRT_VERSION', '1.1.0' );
}

if ( ! defined( 'RVRT_PLUGIN_FILE' ) ) {
	define( 'RVRT_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'RVRT_PLUGIN_DIR' ) ) {
	define( 'RVRT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Autoload the plugin classes.
 *
 * Maps `Acato\RevisionRetention\Some_Class` to `includes/class-some-class.php`.
 *
 * @param string $class_name Fully qualified class name being loaded.
 *
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = RVRT_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( RVRT_PLUGIN_FILE, array( Requirements::class, 'block_activation' ) );
register_activation_hook( RVRT_PLUGIN_FILE, array( Scheduler::class, 'on_activation' ) );
register_deactivation_hook( RVRT_PLUGIN_FILE, array( Scheduler::class, 'on_deactivation' ) );
register_deactivation_hook( RVRT_PLUGIN_FILE, array( Log::class, 'on_deactivation' ) );

// A WordPress old enough to miss the revision APIs this plugin builds on would
// break in ways an administrator cannot act on, so it stops before booting.
if ( ! Requirements::are_met() ) {
	( new Requirements() )->register();

	return;
}

Plugin::instance()->boot();
