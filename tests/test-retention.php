<?php
require_once __DIR__ . '/wp-stubs.php';

use Acato\RevisionRetention\Cleaner;
use Acato\RevisionRetention\Policy;
use Acato\RevisionRetention\Retention_Rule;
use Acato\RevisionRetention\Settings;

$pass = 0;
$fail = 0;

function check( string $label, $actual, $expected ): void {
	global $pass, $fail;
	if ( $actual === $expected ) {
		++$pass;
		echo "  ok   $label\n";
		return;
	}
	++$fail;
	echo "  FAIL $label\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n";
}

function reset_state(): void {
	$GLOBALS['t_options']      = array();
	$GLOBALS['t_site_options'] = array();
	$GLOBALS['t_multisite']    = false;
	$GLOBALS['t_deleted']      = array();
	Policy::flush();
}

/* ---------------------------------------------------------------- 1. Rule */
echo "\nRetention_Rule\n";
reset_state();
check( 'unlimited keep is never sweepable', ( new Retention_Rule( -1, 30 ) )->is_sweepable(), false );
check( 'no age threshold is never sweepable', ( new Retention_Rule( 5, 0 ) )->is_sweepable(), false );
check( 'keep plus age is sweepable', ( new Retention_Rule( 5, 30 ) )->is_sweepable(), true );
check( 'unlimited keep has no cutoff', ( new Retention_Rule( -1, 30 ) )->cutoff_gmt(), '' );

/* ------------------------------------------------- 2. Single site policy */
echo "\nPolicy, single site\n";
reset_state();
update_option( Settings::OPTION, array( 'keep' => 3, 'max_age_days' => 100, 'post_types' => array( 'page' => array( 'keep' => 20 ) ) ) );
check( 'post falls back to the site wide numbers', [ Policy::for_post_type( 'post' )->keep, Policy::for_post_type( 'post' )->max_age_days ], [ 3, 100 ] );
check( 'page overrides keep only, age inherits', [ Policy::for_post_type( 'page' )->keep, Policy::for_post_type( 'page' )->max_age_days ], [ 20, 100 ] );

reset_state();
check( 'unset options use the defaults', [ (int) Settings::get( 'keep' ), (int) Settings::get( 'max_age_days' ) ], [ 5, 365 ] );

/* ---------------------------------------------------- 3. Multisite merge */
echo "\nPolicy, multisite\n";
reset_state();
$GLOBALS['t_multisite'] = true;
update_site_option( Settings::NETWORK_OPTION, array( 'keep' => 5, 'max_age_days' => 365, 'allow_site_override' => true, 'post_types' => array( 'page' => array( 'max_age_days' => 90 ) ) ) );
update_option( Settings::OPTION, array( 'keep' => 20 ) );
Policy::flush();
check( 'site overrides keep, inherits age', [ Policy::for_post_type( 'post' )->keep, Policy::for_post_type( 'post' )->max_age_days ], [ 20, 365 ] );
check( 'network per post type rule survives the merge', Policy::for_post_type( 'page' )->max_age_days, 90 );

$GLOBALS['t_site_options'][ Settings::NETWORK_OPTION ]['allow_site_override'] = false;
Policy::flush();
check( 'site override ignored when the network forbids it', Policy::for_post_type( 'post' )->keep, 5 );

$GLOBALS['t_site_options'][ Settings::NETWORK_OPTION ]['allow_site_override'] = true;
update_option( Settings::OPTION, array( 'keep' => 20, 'post_types' => array( 'page' => array( 'keep' => 1 ) ) ) );
Policy::flush();
check( 'site per post type rule layers over the network one', [ Policy::for_post_type( 'page' )->keep, Policy::for_post_type( 'page' )->max_age_days ], [ 1, 90 ] );

/* --------------------------------------------------- 4. The keep floor */
echo "\nCleaner: the keep floor beats the age threshold\n";
reset_state();
update_option( Settings::OPTION, array( 'keep' => 5, 'max_age_days' => 365 ) );

// A post edited only long ago: forty revisions, every one of them older than
// the threshold. The floor must still save the newest five.
$revisions = array();
for ( $i = 1; $i <= 40; $i++ ) {
	$revisions[] = array( 'ID' => 1000 + $i, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( ( 400 + ( 40 - $i ) * 10 ) * DAY_IN_SECONDS ) ) );
}
usort( $revisions, fn( $a, $b ) => strcmp( $b['post_date_gmt'], $a['post_date_gmt'] ) );

$GLOBALS['wpdb']->candidates = array( array( 'parent_id' => 7, 'post_type' => 'post' ) );
$GLOBALS['wpdb']->revisions  = array( 7 => $revisions );

$result = ( new Cleaner() )->sweep( 100, false );
check( 'dormant post keeps its newest five', $result->revisions, 35 );
check( 'the five survivors are the newest', count( array_intersect( $GLOBALS['t_deleted'], array_column( array_slice( $revisions, 0, 5 ), 'ID' ) ) ), 0 );
check( 'deletions actually happened', count( $GLOBALS['t_deleted'] ), 35 );

/* ------------------------------------------------------------ 5. Dry run */
echo "\nCleaner: dry run\n";
$GLOBALS['t_deleted'] = array();
$dry = ( new Cleaner() )->sweep( 100, true );
check( 'dry run reports the same number', $dry->revisions, 35 );
check( 'dry run deletes nothing', count( $GLOBALS['t_deleted'] ), 0 );

/* ------------------------------------------------ 6. Recent history kept */
echo "\nCleaner: recent history is left alone\n";
reset_state();
update_option( Settings::OPTION, array( 'keep' => 5, 'max_age_days' => 365 ) );
$recent = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$recent[] = array( 'ID' => 2000 + $i, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( $i * DAY_IN_SECONDS ) ) );
}
$GLOBALS['wpdb']->candidates = array( array( 'parent_id' => 9, 'post_type' => 'post' ) );
$GLOBALS['wpdb']->revisions  = array( 9 => $recent );
check( 'nothing under the age threshold is removed', ( new Cleaner() )->sweep( 100, false )->revisions, 0 );

/* --------------------------------------------------------- 7. Exclusions */
echo "\nCleaner: exclusions and unlimited keep\n";
reset_state();
update_option( Settings::OPTION, array( 'keep' => 5, 'max_age_days' => 365 ) );
$GLOBALS['wpdb']->candidates = array( array( 'parent_id' => 7, 'post_type' => 'post' ) );
$GLOBALS['wpdb']->revisions  = array( 7 => $revisions );
add_filter( 'revision_retention_excluded_posts', fn( $ids ) => array( 7 ) );
check( 'an excluded post is skipped entirely', ( new Cleaner() )->sweep( 100, false )->revisions, 0 );
$GLOBALS['t_filters'] = array();

reset_state();
update_option( Settings::OPTION, array( 'keep' => -1, 'max_age_days' => 365 ) );
$GLOBALS['wpdb']->candidates = array( array( 'parent_id' => 7, 'post_type' => 'post' ) );
$GLOBALS['wpdb']->revisions  = array( 7 => $revisions );
check( 'unlimited keep purges nothing at all', ( new Cleaner() )->sweep( 100, false )->revisions, 0 );

/* ------------------------------------------------------------ 8. Batching */
echo "\nCleaner: batching and the cursor\n";
reset_state();
update_option( Settings::OPTION, array( 'keep' => 0, 'max_age_days' => 365 ) );
$old = array( array( 'ID' => 3001, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 800 * DAY_IN_SECONDS ) ) ) );
$GLOBALS['wpdb']->candidates = array();
$GLOBALS['wpdb']->revisions  = array();
foreach ( range( 1, 5 ) as $n ) {
	$GLOBALS['wpdb']->candidates[]      = array( 'parent_id' => $n * 10, 'post_type' => 'post' );
	$GLOBALS['wpdb']->revisions[ $n * 10 ] = $old;
}
$first = ( new Cleaner() )->sweep( 2, false );
check( 'a full batch is not the end', $first->finished, false );
check( 'the cursor follows the last post seen', $first->cursor, 20 );
$second = ( new Cleaner() )->sweep( 2, false, $first->cursor );
check( 'the next batch resumes after the cursor', $second->cursor, 40 );
$third = ( new Cleaner() )->sweep( 2, false, $second->cursor );
check( 'a short batch ends the sweep', $third->finished, true );
check( 'every post was visited exactly once', count( $GLOBALS['t_deleted'] ), 5 );

/* --------------------------------------------------------- 9. Sanitizing */
echo "\nSettings::sanitize\n";
reset_state();
$s = Settings::sanitize( array( 'keep' => '-9', 'max_age_days' => '-5', 'batch_size' => '99999', 'cron_interval' => 'nonsense' ) );
check( 'keep is normalised to unlimited', $s['keep'], -1 );
check( 'a negative age becomes no threshold', $s['max_age_days'], 0 );
check( 'batch size is clamped', $s['batch_size'], 5000 );
check( 'an unknown interval falls back', $s['cron_interval'], 'daily' );
check( 'unknown post types are dropped', Settings::sanitize( array( 'enable_revisions' => array( 'product', 'bogus' ) ) )['enable_revisions'], array( 'product' ) );

$GLOBALS['t_multisite'] = true;
$s = Settings::sanitize( array( 'keep' => '', 'max_age_days' => '30' ) );
check( 'an empty site field inherits rather than storing a default', isset( $s['keep'] ), false );
check( 'a filled site field is stored', $s['max_age_days'], 30 );

echo "\n" . str_repeat( '-', 52 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
