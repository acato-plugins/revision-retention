<?php
require_once __DIR__ . '/wp-stubs.php';

use Acato\RevisionRetention\Assets;
use Acato\RevisionRetention\Cleaner;
use Acato\RevisionRetention\Cron_Health;
use Acato\RevisionRetention\Log;
use Acato\RevisionRetention\Plugin;
use Acato\RevisionRetention\Rating_Notice;
use Acato\RevisionRetention\Scheduler;
use Acato\RevisionRetention\Sweep_Result;
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
	$GLOBALS['t_caps']         = array( 'manage_options', 'manage_network_options', 'activate_plugins' );
	$GLOBALS['t_deleted']      = array();
	$GLOBALS['t_cron']         = array();
	$GLOBALS['t_transients']   = array();
	$GLOBALS['t_user_meta']    = array();
	$GLOBALS['t_sites']        = array();
	$GLOBALS['wpdb']->updates  = array();
	$GLOBALS['wpdb']->row      = null;
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
check( 'the sweep defaults to once a week', [ (string) Settings::get( 'cron_interval' ), Settings::interval_seconds() ], [ 'weekly', WEEK_IN_SECONDS ] );

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

/* --------------------------------------------------- 4. Eligibility */
echo "\nPost_Types::eligible\n";
reset_state();
$eligible = array_keys( Acato\RevisionRetention\Post_Types::eligible() );
check( 'a post type with an editor is offered', in_array( 'product', $eligible, true ), true );
check( 'a post type with nothing to revise is left out', in_array( 'ledger', $eligible, true ), false );
check( 'the revision post type itself is never offered', in_array( 'revision', $eligible, true ), false );

/* ----------------------------------- 5. Who may sweep a locked down site */
echo "\nSettings::may_sweep\n";
reset_state();
check( 'a single site always may', Settings::may_sweep(), true );

$GLOBALS['t_multisite'] = true;
update_site_option( Settings::NETWORK_OPTION, array( 'allow_site_override' => true ) );
$GLOBALS['t_caps'] = array( 'manage_options' );
check( 'a site administrator may while the network allows overrides', Settings::may_sweep(), true );

// With the policy locked, deleting belongs to whoever set the policy. Otherwise
// a network could switch the sweep off and a site could still press the button.
update_site_option( Settings::NETWORK_OPTION, array( 'allow_site_override' => false ) );
check( 'a site administrator may not once the policy is locked', Settings::may_sweep(), false );

$GLOBALS['t_caps'] = array( 'manage_options', 'manage_network_options' );
check( 'a network administrator still may', Settings::may_sweep(), true );

/* ------------------------------------- 6. Promoting a site to the network */
echo "\nSettings::promote_to_network\n";
reset_state();
$GLOBALS['t_multisite'] = true;
update_site_option( Settings::NETWORK_OPTION, array( 'keep' => 5, 'max_age_days' => 365, 'allow_site_override' => true ) );
update_option( Settings::OPTION, array( 'keep' => 20 ) );
Policy::flush();

// The site overrides keep and inherits the age. Both must land on the network.
Settings::promote_to_network();
Policy::flush();
check( 'the overridden value becomes the network default', (int) Settings::network()['keep'], 20 );
check( 'the inherited value is carried up unchanged', (int) Settings::network()['max_age_days'], 365 );
check( 'the site keeps nothing of its own', get_option( Settings::OPTION, 'gone' ), 'gone' );
check( 'and so ends up with exactly what it had', [ (int) Settings::get( 'keep' ), (int) Settings::get( 'max_age_days' ) ], [ 20, 365 ] );
check( 'the network keeps deciding who may override', Settings::allows_site_override(), true );

// A site cannot hand that decision upwards along with everything else.
reset_state();
$GLOBALS['t_multisite'] = true;
update_site_option( Settings::NETWORK_OPTION, array( 'keep' => 5, 'allow_site_override' => false ) );
update_option( Settings::OPTION, array( 'keep' => 99 ) );
Policy::flush();
Settings::promote_to_network();
check( 'the override switch survives a promotion', Settings::allows_site_override(), false );
check( 'and a site that could not override promoted nothing', (int) Settings::network()['keep'], 5 );

reset_state();
Settings::promote_to_network();
check( 'promoting does nothing on a single site', get_option( Settings::NETWORK_OPTION, 'none' ), 'none' );

/* --------------------------------------------------- 6. The keep floor */
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
// What the list shows a tester: 35 going, 5 staying, which is the floor made visible.
check( 'the list reports what goes', $result->items[0]['revisions'], 35 );
check( 'and what the floor keeps back', $result->items[0]['kept'], 5 );

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

/* ------------------------------------------------- 9. The deletion cap */
echo "\nCleaner: the cap on revisions per batch\n";
reset_state();
update_option( Settings::OPTION, array( 'keep' => 0, 'max_age_days' => 365 ) );
$ten = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$ten[] = array( 'ID' => 4000 + $i, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 800 * DAY_IN_SECONDS ) ) );
}
$GLOBALS['wpdb']->candidates = array();
$GLOBALS['wpdb']->revisions  = array();
foreach ( range( 1, 5 ) as $n ) {
	$GLOBALS['wpdb']->candidates[]         = array( 'parent_id' => $n * 10, 'post_type' => 'post' );
	$GLOBALS['wpdb']->revisions[ $n * 10 ] = $ten;
}

// Five posts of ten old revisions each. A cap of 25 must stop after the third
// post rather than part way through it, so nothing is left half cleaned.
$capped = ( new Cleaner() )->sweep( 100, false, 0, array(), 25 );
check( 'each swept post reports nothing left behind', array_unique( array_column( $capped->items, 'kept' ) ), array( 0 ) );
check( 'the cap stops the batch at a post boundary', $capped->revisions, 30 );
check( 'only whole posts were processed', $capped->posts, 3 );
check( 'a capped batch is never reported as finished', $capped->finished, false );
check( 'the cursor is the last post finished', $capped->cursor, 30 );

// And the next batch picks up the rest.
$rest = ( new Cleaner() )->sweep( 100, false, $capped->cursor, array(), 25 );
check( 'the next batch takes what is left', $rest->revisions, 20 );
check( 'and that batch does finish', $rest->finished, true );
check( 'every revision went exactly once', count( $GLOBALS['t_deleted'] ), 50 );

reset_state();
update_option( Settings::OPTION, array( 'keep' => 0, 'max_age_days' => 365 ) );
check( 'no cap means the whole batch runs', ( new Cleaner() )->sweep( 100, false, 0, array(), 0 )->revisions, 50 );

/* ------------------------------------------- 10. The list of affected posts */
echo "\nCleaner: naming the posts a batch touches\n";
reset_state();
update_option( Settings::OPTION, array( 'keep' => 0, 'max_age_days' => 365 ) );
$GLOBALS['wpdb']->candidates = array(
	array( 'parent_id' => 11, 'post_type' => 'post' ),
	array( 'parent_id' => 12, 'post_type' => 'post' ),
);
$old_one = array( array( 'ID' => 5001, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 800 * DAY_IN_SECONDS ) ) ) );
$recent  = array( array( 'ID' => 5002, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
$GLOBALS['wpdb']->revisions = array( 11 => $old_one, 12 => $recent );

$listed = ( new Cleaner() )->sweep( 100, true );
check( 'only posts that actually lose something are listed', count( $listed->items ), 1 );
check( 'two posts were scanned', $listed->posts, 2 );
check( 'but only one is reported as affected', $listed->affected, 1 );
check( 'the listed post is the one with old revisions', $listed->items[0]['id'], 11 );
check( 'it reports how many it would lose', $listed->items[0]['revisions'], 1 );
check( 'and how many it would keep', $listed->items[0]['kept'], 0 );
check( 'it carries a title to show, entities decoded', $listed->items[0]['title'], "Post \xe2\x80\x93 11" );

// The screen puts the edit link into an href, so a hostile filter on
// get_edit_post_link() must not be able to smuggle a script in.
$GLOBALS['t_edit_link'] = 'javascript:alert(1)';
$GLOBALS['wpdb']->revisions = array( 11 => $old_one, 12 => $recent );
$hostile = ( new Cleaner() )->sweep( 100, true );
check( 'a javascript: edit link is dropped', $hostile->items[0]['editUrl'], '' );
unset( $GLOBALS['t_edit_link'] );

/* ------------------------------------------------ 11. Autosaves are spared */
echo "\nCleaner: autosaves are excluded in SQL\n";
reset_state();
update_option( Settings::OPTION, array( 'keep' => 0, 'max_age_days' => 365 ) );
$GLOBALS['wpdb']->queries    = array();
$GLOBALS['wpdb']->candidates = array( array( 'parent_id' => 11, 'post_type' => 'post' ) );
$GLOBALS['wpdb']->revisions  = array( 11 => $old_one );
( new Cleaner() )->sweep( 10, true );

// The exclusion lives in the WHERE clause, so this guards the clause itself:
// a fixture cannot run SQL, but it can insist the predicate is still written.
$looked_for_parents = false;
$looked_for_one     = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	$excludes = str_contains( $q['query'], 'post_name NOT LIKE %s' )
		&& in_array( '%-autosave-v1', $q['args'], true );

	if ( str_contains( $q['query'], 'GROUP BY r.post_parent' ) && $excludes ) {
		$looked_for_parents = true;
	}
	if ( str_contains( $q['query'], 'ORDER BY post_date_gmt DESC' ) && $excludes ) {
		$looked_for_one = true;
	}
}
check( 'the candidate query excludes autosaves', $looked_for_parents, true );
check( 'the per post query excludes autosaves', $looked_for_one, true );
check( 'and the counts query does too', str_contains( Cleaner::counts() === array() ? implode( '', array_column( $GLOBALS['wpdb']->queries, 'query' ) ) : implode( '', array_column( $GLOBALS['wpdb']->queries, 'query' ) ), 'post_name NOT LIKE %s' ), true );

/* --------------------------------------------------------- 12. Sanitizing */
echo "\nSettings::sanitize\n";
reset_state();
$s = Settings::sanitize( array( 'keep' => '-9', 'max_age_days' => '-5', 'batch_size' => '99999', 'cron_interval' => 'nonsense' ) );
check( 'keep is normalised to unlimited', $s['keep'], -1 );
check( 'a negative age becomes no threshold', $s['max_age_days'], 0 );
check( 'batch size is clamped', $s['batch_size'], 5000 );
check( 'the deletion cap is clamped', Settings::sanitize( array( 'max_deletions' => '999999' ) )['max_deletions'], 100000 );
check( 'the deletion cap may be lifted with zero', Settings::sanitize( array( 'max_deletions' => '0' ) )['max_deletions'], 0 );
check( 'the deletion cap defaults per batch', (int) Settings::get( 'max_deletions' ), 1000 );
check( 'an unknown interval falls back to the default', $s['cron_interval'], 'weekly' );

$s = Settings::sanitize( array( 'log_enabled' => '1', 'log_retention' => 'forever' ) );
check( 'the log can be switched on', $s['log_enabled'], true );
check( 'an unknown log retention falls back to three months', $s['log_retention'], 'quarter' );
check( 'a known log retention is kept', Settings::sanitize( array( 'log_retention' => 'year' ) )['log_retention'], 'year' );

reset_state();
check( 'the log is on until switched off', Settings::get( 'log_enabled' ), true );
update_option( Settings::OPTION, array( 'log_retention' => 'week' ) );
check( 'log retention resolves to days', Settings::log_retention_days(), 7 );
check( 'unknown post types are dropped', Settings::sanitize( array( 'enable_revisions' => array( 'product', 'bogus' ) ) )['enable_revisions'], array( 'product' ) );

// The screen offers a list of durations, but that list is an affordance: a
// value from a filter or from WP-CLI must survive being stored and read back.
check( 'an age outside the offered list is accepted', Settings::sanitize( array( 'max_age_days' => '45' ) )['max_age_days'], 45 );
check( 'an offered age is described with its label', Settings::describe_age( 365 ), '1 year' );
check( 'any other age is still described in days', Settings::describe_age( 45 ), '45 days' );

$GLOBALS['t_multisite'] = true;
$s = Settings::sanitize( array( 'keep' => '', 'max_age_days' => '30' ) );
check( 'an empty site field inherits rather than storing a default', isset( $s['keep'] ), false );
check( 'a filled site field is stored', $s['max_age_days'], 30 );

/* ------------------------------------------------- 13. Scheduler::sync */
echo "\nScheduler::sync\n";
reset_state();
Scheduler::sync();
$booked = (int) wp_next_scheduled( Scheduler::HOOK );
check( 'a fresh install books its first sweep an interval out, not in a minute', abs( $booked - ( time() + WEEK_IN_SECONDS ) ) <= 2, true );

reset_state();
Scheduler::on_activation();
$activated = (int) wp_next_scheduled( Scheduler::HOOK );
Scheduler::sync();
check( 'the first save after activation leaves the booking where it was', (int) wp_next_scheduled( Scheduler::HOOK ), $activated );

reset_state();
update_option( Scheduler::CURSOR_OPTION, array( 'cursor' => 40 ) );
$GLOBALS['t_cron'][ Scheduler::HOOK ] = time() + DAY_IN_SECONDS;
Scheduler::sync();
check( 'a sweep part way continues within the minute', (int) wp_next_scheduled( Scheduler::HOOK ) <= time() + MINUTE_IN_SECONDS, true );

reset_state();
$finished = time() - 2 * DAY_IN_SECONDS;
update_option( Scheduler::CURSOR_OPTION, array( 'finished' => $finished ) );
Scheduler::sync();
check( 'the next full sweep falls due an interval after the last one finished', (int) wp_next_scheduled( Scheduler::HOOK ), $finished + WEEK_IN_SECONDS );

$GLOBALS['t_cron'][ Scheduler::HOOK ] = time() + HOUR_IN_SECONDS;
Scheduler::sync();
check( 'a booking sooner than that stays', (int) wp_next_scheduled( Scheduler::HOOK ), time() + HOUR_IN_SECONDS );

update_option( Settings::OPTION, array( 'cron_enabled' => false ) );
Scheduler::sync();
check( 'switching the schedule off clears the booking', wp_next_scheduled( Scheduler::HOOK ), false );

/* ---------------------------------------------- 14. Scheduler::advance */
echo "\nScheduler::advance\n";
reset_state();
update_option( Settings::OPTION, array( 'log_enabled' => false ) );
Scheduler::advance( Log::SOURCE_CLI, new Sweep_Result( 120, 4, 9, false, false ) );
$state = Scheduler::state();
check( 'a batch part way stores the cursor and the running totals', [ $state['cursor'], $state['revisions'], $state['finished'] ], [ 120, 9, 0 ] );

Scheduler::advance( Log::SOURCE_CLI, new Sweep_Result( 0, 2, 3, true, false ) );
$state = Scheduler::state();
check( 'the batch that finishes resets the cursor and reports the whole sweep', [ $state['cursor'], $state['removed'], $state['finished'] > 0 ], [ 0, 12, true ] );

/* -------------------------------------------- 15. A sweep given up on */
echo "\nLog::stop\n";
reset_state();
update_option( Log::DB_VERSION_OPTION, '1' );
update_option( Scheduler::CURSOR_OPTION, array( 'cursor' => 40, 'log' => 7 ) );
Scheduler::reset_cursor();
$update = $GLOBALS['wpdb']->updates[0] ?? array( array(), array() );
check( 'a restart marks the entry it leaves behind as stopped', [ $update[0]['finished'] ?? null, $update[1]['id'] ?? null ], [ 2, 7 ] );
check( 'and only while that entry is still open', $update[1]['finished'] ?? null, 0 );
check( 'the restart forgets the entry', Scheduler::state()['log'], 0 );

reset_state();
update_option( Log::DB_VERSION_OPTION, '1' );
update_option( Scheduler::CURSOR_OPTION, array( 'cursor' => 40, 'log' => 8 ) );
Scheduler::on_deactivation();
check( 'deactivation part way marks the entry as stopped too', $GLOBALS['wpdb']->updates[0][1]['id'] ?? null, 8 );

$now = gmdate( 'Y-m-d H:i:s' );
check( 'a stopped entry reads as stopped straight away', Log::status( array( 'finished' => 2, 'ended_gmt' => $now ) ), 'stopped' );
check( 'a finished entry reads as finished', Log::status( array( 'finished' => 1, 'ended_gmt' => $now ) ), 'finished' );
check( 'an open entry with a recent batch reads as running', Log::status( array( 'finished' => 0, 'ended_gmt' => $now ) ), 'running' );

/* ------------------------------------------------------- 16. Cron_Health */
echo "\nCron_Health\n";
reset_state();
check( 'an empty queue is never overdue', Cron_Health::overdue(), 0 );
check( 'and cron counts as running', Cron_Health::check()['status'], Cron_Health::RUNNING );

$GLOBALS['t_cron']['rvrt_sweep'] = time() - 2 * HOUR_IN_SECONDS;
check( 'an event two hours late with nothing firing it is stalled', Cron_Health::check()['status'], Cron_Health::STALLED );

$GLOBALS['timestart'] = microtime( true );
set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) ) );
check( 'the visit that just woke a quiet site is not a false alarm', Cron_Health::check()['status'], Cron_Health::RUNNING );
check( 'asked again in the same request, the answer holds', Cron_Health::check()['status'], Cron_Health::RUNNING );

reset_state();
$late = time() - 3 * HOUR_IN_SECONDS;
$GLOBALS['t_cron']['rvrt_sweep'] = $late;
set_transient( Cron_Health::EXCUSED, $late );
set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) ) );
check( 'the same event still late after it was excused once is stalled', Cron_Health::check()['status'], Cron_Health::STALLED );

reset_state();
$GLOBALS['t_cron']['rvrt_sweep'] = time() - 5 * HOUR_IN_SECONDS;
set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) - 300 ) );
check( 'a lock from an earlier request excuses nothing', Cron_Health::check()['status'], Cron_Health::STALLED );

/* ----------------------------------------------------- 17. Rating_Notice */
echo "\nRating_Notice::is_due\n";
reset_state();
check( 'not before the plugin knows when it was installed', Rating_Notice::is_due( false ), false );
$installed_option = ( new ReflectionClassConstant( Rating_Notice::class, 'INSTALLED_OPTION' ) )->getValue();
$GLOBALS['t_site_options'] = array( $installed_option => time() - 10 * DAY_IN_SECONDS );
check( 'not within the first month', Rating_Notice::is_due( false ), false );
$GLOBALS['t_site_options'][ $installed_option ] = time() - 40 * DAY_IN_SECONDS;
check( 'due a month after activation', Rating_Notice::is_due( false ), true );
$GLOBALS['t_user_meta'][ Rating_Notice::USER_META ] = (string) ( time() + DAY_IN_SECONDS );
check( 'not while "Maybe later" is running', Rating_Notice::is_due( false ), false );
$GLOBALS['t_user_meta'][ Rating_Notice::USER_META ] = (string) ( time() - 1 );
check( 'due again once it has run out', Rating_Notice::is_due( false ), true );
$GLOBALS['t_user_meta'][ Rating_Notice::USER_META ] = 'done';
check( 'never again after an answer', Rating_Notice::is_due( false ), false );
$GLOBALS['t_caps'] = array();
$GLOBALS['t_user_meta'] = array();
check( 'never for a user who cannot change the settings', Rating_Notice::is_due( false ), false );

/* ------------------------------------------------------------ 18. Assets */
echo "\nAssets::url\n";
reset_state();
$built = is_readable( __DIR__ . '/../dist/manifest.json' );
$css   = (string) Assets::url( 'src/settings.css' );
check( 'the stylesheet resolves, built or not', '' !== $css, true );
check(
	$built ? 'with a build, the hashed file in dist/' : 'without a build, the source with its modification time',
	$built ? str_contains( $css, '/dist/settings-' ) : str_contains( $css, '/src/settings.css?ver=' ),
	true
);
check( 'a file that is neither built nor there is null', Assets::url( 'src/missing.js' ), null );

/* --------------------------------------------------------- 19. Plugin::sites */
echo "\nPlugin::sites\n";
reset_state();
$basename = plugin_basename( RVRT_PLUGIN_FILE );
$GLOBALS['t_sites'] = array(
	1 => array( 'active_plugins' => array( $basename ) ),
	2 => array( 'active_plugins' => array( 'other/other.php' ) ),
	3 => array( 'active_plugins' => array( 'other/other.php', $basename ) ),
);
$GLOBALS['t_multisite'] = true;
check( 'activated per site, only the sites that turned it on', Plugin::sites( true ), array( 1, 3 ) );

// Site 2 turns the plugin on without the list being told.
$GLOBALS['t_sites'][2]['active_plugins'][] = $basename;
check( 'the rest of a sweep goes by the list its first batch made', Plugin::sites(), array( 1, 3 ) );
check( 'the next sweep looks again', Plugin::sites( true ), array( 1, 2, 3 ) );

unset( $GLOBALS['t_sites'][3] );
check( 'a site deleted since drops out of the kept list', Plugin::sites(), array( 1, 2 ) );

$GLOBALS['t_sites'][2]['active_plugins'] = array();
Plugin::forget_sites();
check( 'activating or deactivating anywhere makes it look again', Plugin::sites(), array( 1 ) );

$GLOBALS['t_site_options']['active_sitewide_plugins'] = array( $basename => time() );
check( 'activated on the network, every site', Plugin::sites(), array( 1, 2 ) );

/* --------------------------------------------------- 20. Cleaner::progress */
echo "\nCleaner::progress\n";
reset_state();
$GLOBALS['wpdb']->queries = array();
$GLOBALS['wpdb']->row     = array( 'total' => 200, 'done' => 50 );
check( 'progress is the share of posts at or before the cursor', Cleaner::progress( 500 ), 0.25 );
$sql = (string) ( end( $GLOBALS['wpdb']->queries )['query'] ?? '' );
check( 'counted over posts, not posts with revisions, so a keep of 0 cannot empty both sides', str_contains( $sql, "'revision'" ), false );

/* ------------------------------------------------------- 21. Uninstall */
echo "\nuninstall.php\n";
// Uninstall runs without the plugin's autoloader, so every class it names
// has to be required by hand, or it stops with a fatal half way.
$uninstall = (string) file_get_contents( __DIR__ . '/../uninstall.php' );
preg_match_all( '/\b([A-Z][A-Za-z_]+)::/', $uninstall, $used );
preg_match_all( "#includes/class-([a-z-]+)\.php#", $uninstall, $loaded );
$missing = array_values( array_diff( array_unique( $used[1] ), array_map( static fn( string $f ): string => str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $f ) ) ), $loaded[1] ) ) );
check( 'every class uninstall.php uses is required by it', $missing, array() );

echo "\n" . str_repeat( '-', 52 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
