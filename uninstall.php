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

require_once __DIR__ . '/includes/class-retention-rule.php';
require_once __DIR__ . '/includes/class-post-types.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-scheduler.php';

use Acato\RevisionRetention\Scheduler;
use Acato\RevisionRetention\Settings;

/**
 * Remove this site's options and anything it has booked.
 *
 * @return void
 */
function rvrt_uninstall_site(): void {
	if ( empty( Settings::get( 'remove_data_on_uninstall' ) ) ) {
		return;
	}

	wp_clear_scheduled_hook( Scheduler::HOOK );

	delete_option( Settings::OPTION );
	delete_option( Scheduler::CURSOR_OPTION );
}

if ( ! is_multisite() ) {
	rvrt_uninstall_site();

	return;
}

// Every site decides for itself, so each one is visited and asked. The
// sites are walked in chunks to keep a large network out of memory trouble.
$rvrt_offset = 0;

do {
	$rvrt_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 100,
			'offset' => $rvrt_offset,
		)
	);

	$rvrt_found = count( $rvrt_sites );

	foreach ( $rvrt_sites as $rvrt_site_id ) {
		switch_to_blog( (int) $rvrt_site_id );
		rvrt_uninstall_site();
		restore_current_blog();
	}

	$rvrt_offset += 100;
} while ( 100 === $rvrt_found );

if ( ! empty( Settings::network()['remove_data_on_uninstall'] ) ) {
	delete_site_option( Settings::NETWORK_OPTION );
}
