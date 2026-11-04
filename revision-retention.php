<?php
/**
 * Plugin Name:       Revision Retention
 * Plugin URI:        https://github.com/acato-plugins/revision-retention
 * Description:       Give post revisions a retention policy: keep the newest few, drop the ones older than a threshold, per post type, on a schedule or from WP-CLI.
 * Version:           1.5.0
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

use Acato\RevisionRetention\Includes\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'RVRT_VERSION' ) ) {
	define( 'RVRT_VERSION', '1.5.0' );
}

if ( ! defined( 'RVRT_PLUGIN_FILE' ) ) {
	define( 'RVRT_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'RVRT_PLUGIN_DIR' ) ) {
	define( 'RVRT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Autoload the plugin classes, traits and interfaces.
 *
 * Maps `Acato\RevisionRetention\Admin\SettingsPage` to
 * `admin/class-settingspage.php`, and likewise for `Includes`, trying
 * `class-`, then `trait-`, then `interface-`.
 *
 * Up to 1.5.0 every class lived in the `Acato\RevisionRetention` namespace
 * itself, under its WordPress style name. Code written against those names,
 * a snippet calling `Acato\RevisionRetention\Settings::get()` for instance,
 * keeps working: the old name is an alias of the class that took its place.
 *
 * @param string $class_name Fully qualified name of the class being loaded.
 *
 * @return void
 */
function rvrt_autoload( string $class_name ): void {
	$prefix = 'Acato\\RevisionRetention\\';

	if ( ! str_starts_with( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$legacy   = rvrt_legacy_classes()[ $relative ] ?? null;

	if ( null !== $legacy ) {
		if ( class_exists( $prefix . $legacy ) ) {
			class_alias( $prefix . $legacy, $class_name );
		}

		return;
	}

	$parts = explode( '\\', strtolower( str_replace( '_', '-', $relative ) ) );
	$name  = array_pop( $parts );
	$dir   = RVRT_PLUGIN_DIR . ( [] === $parts ? 'includes' : implode( '/', $parts ) ) . '/';

	foreach ( [ 'class', 'trait', 'interface' ] as $kind ) {
		$file = $dir . $kind . '-' . $name . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;

			return;
		}
	}
}

/**
 * The class names up to 1.5.0, each with the name of the class that replaced it.
 *
 * @return array<string, string> Old name => new name, both below `Acato\RevisionRetention\`.
 */
function rvrt_legacy_classes(): array {
	return [
		'Assets'           => 'Admin\\Assets',
		'CLI'              => 'Includes\\Cli',
		'Cleaner'          => 'Includes\\Cleaner',
		'Cron_Health'      => 'Includes\\CronHealth',
		'Dashboard_Widget' => 'Admin\\DashboardWidget',
		'Limits'           => 'Includes\\Limits',
		'Log'              => 'Includes\\Log',
		'Log_View'         => 'Admin\\LogView',
		'Plugin'           => 'Includes\\Plugin',
		'Policy'           => 'Includes\\Policy',
		'Post_Types'       => 'Includes\\PostTypes',
		'Rating_Notice'    => 'Admin\\RatingNotice',
		'Requirements'     => 'Includes\\Requirements',
		'Retention_Rule'   => 'Includes\\RetentionRule',
		'Scheduler'        => 'Includes\\Scheduler',
		'Settings'         => 'Includes\\Settings',
		'Settings_Page'    => 'Admin\\SettingsPage',
		'Sweep_Result'     => 'Includes\\SweepResult',
	];
}

spl_autoload_register( 'rvrt_autoload' );

Plugin::instance();
