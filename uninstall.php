<?php
/**
 * Removes the plugin data when it is uninstalled.
 *
 * Only runs for a site that has "Remove all data of this plugin when it is
 * uninstalled" switched on, so a site that wants to keep its policy through a
 * reinstall keeps it.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The plugin's autoloader is not loaded on uninstall, so every class used
// below is required by hand, its parent first.
require_once __DIR__ . '/includes/class-singleton.php';
require_once __DIR__ . '/includes/class-retentionrule.php';
require_once __DIR__ . '/includes/class-posttypes.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-scheduler.php';
require_once __DIR__ . '/includes/class-cronhealth.php';
require_once __DIR__ . '/includes/class-log.php';
require_once __DIR__ . '/includes/class-upgrade.php';
require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/admin/class-ratingnotice.php';
require_once __DIR__ . '/admin/class-tour.php';

use Acato\RevisionRetention\Admin\RatingNotice;
use Acato\RevisionRetention\Admin\Tour;
use Acato\RevisionRetention\Includes\CronHealth;
use Acato\RevisionRetention\Includes\Log;
use Acato\RevisionRetention\Includes\Plugin;
use Acato\RevisionRetention\Includes\Scheduler;
use Acato\RevisionRetention\Includes\Settings;
use Acato\RevisionRetention\Includes\Upgrade;

/**
 * Remove this site's options and anything it has booked.
 *
 * @return void
 */
function rvrt_uninstall_site(): void {
	// Whatever is kept, nothing may stay booked: with the plugin gone, nothing
	// would handle the events, and the daily prune would book itself forever.
	// Network deactivation only clears them on the main site, so every site
	// is cleared here.
	wp_clear_scheduled_hook( Scheduler::HOOK );
	wp_clear_scheduled_hook( Log::PRUNE_HOOK );
	delete_option( Scheduler::LOCK_OPTION );

	if ( empty( Settings::get( 'remove_data_on_uninstall' ) ) ) {
		return;
	}

	delete_option( Settings::OPTION );
	delete_option( Scheduler::CURSOR_OPTION );
	delete_option( Scheduler::LAST_RUN_OPTION );
	delete_option( Upgrade::VERSION_OPTION );
	delete_transient( CronHealth::EXCUSED );

	Log::uninstall();
}

if ( ! is_multisite() ) {
	if ( ! empty( Settings::get( 'remove_data_on_uninstall' ) ) ) {
		RatingNotice::uninstall();
		Tour::uninstall();
	}

	rvrt_uninstall_site();

	return;
}

// Every site decides for itself, so each one is visited and asked. The
// sites are walked in chunks to keep a large network out of memory trouble.
$rvrt_offset = 0;

do {
	$rvrt_sites = get_sites(
		[
			'fields' => 'ids',
			'number' => 100,
			'offset' => $rvrt_offset,
		]
	);

	$rvrt_found = count( $rvrt_sites );

	foreach ( $rvrt_sites as $rvrt_site_id ) {
		switch_to_blog( (int) $rvrt_site_id );
		rvrt_uninstall_site();
		restore_current_blog();
	}

	$rvrt_offset += 100;
} while ( 100 === $rvrt_found );

// Only caches, of where the plugin was active and of where the schedule
// stands, so they go whatever was chosen.
delete_site_option( Plugin::SITES_OPTION );
delete_site_transient( Scheduler::NETWORK_TRANSIENT );

if ( ! empty( Settings::network()['remove_data_on_uninstall'] ) ) {
	delete_site_option( Settings::NETWORK_OPTION );
	RatingNotice::uninstall();
	Tour::uninstall();
}
