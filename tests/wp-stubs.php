<?php
// Just enough WordPress to exercise the plugin's own logic outside a site.

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['t_options']      = array();
$GLOBALS['t_site_options'] = array();
$GLOBALS['t_multisite']    = false;
$GLOBALS['t_filters']      = array();
$GLOBALS['t_deleted']      = array();
// Feature support per post type, so post_type_supports() can tell 'editor'
// from 'revisions' the way WordPress does.
$GLOBALS['t_supports'] = array(
	'post'      => array( 'editor', 'revisions' ),
	'page'      => array( 'editor', 'revisions' ),
	'product'   => array( 'editor' ),
	'ledger'    => array( 'title', 'custom-fields' ),
	'revision'  => array( 'editor' ),
);

function is_multisite() { return (bool) $GLOBALS['t_multisite']; }
function get_option( $k, $d = false ) { return $GLOBALS['t_options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function get_site_option( $k, $d = false ) { return $GLOBALS['t_site_options'][ $k ] ?? $d; }
function update_site_option( $k, $v ) { $GLOBALS['t_site_options'][ $k ] = $v; return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['t_filters'][ $h ][] = $cb; }
function add_action( $h, $cb, $p = 10, $a = 1 ) {}
function apply_filters( $h, $v, ...$rest ) {
	foreach ( $GLOBALS['t_filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v, ...$rest ); }
	return $v;
}
function __( $s, $d = null ) { return $s; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function post_type_exists( $t ) { return array_key_exists( $t, $GLOBALS['t_supports'] ); }
function post_type_supports( $t, $f ) { return in_array( $f, $GLOBALS['t_supports'][ $t ] ?? array(), true ); }
function add_post_type_support( $t, $f ) { $GLOBALS['t_supports'][ $t ][] = $f; }
function wp_delete_post_revision( $id ) { $GLOBALS['t_deleted'][] = (int) $id; return true; }
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_single_event( $t, $h ) { return true; }
function wp_clear_scheduled_hook( $h ) { return 0; }
function get_post_types( $args = array(), $output = 'names' ) {
	$types = array();
	foreach ( array( 'post' => 'Posts', 'page' => 'Pages', 'product' => 'Products', 'ledger' => 'Ledger entries', 'revision' => 'Revisions' ) as $name => $label ) {
		$o          = new stdClass();
		$o->name    = $name;
		$o->public  = true;
		$o->show_ui = true;
		$o->labels  = (object) array( 'name' => $label );
		$types[ $name ] = $o;
	}
	return $types;
}

// A wpdb that answers from fixtures instead of MySQL, so the sweep's own
// selection logic (the keep floor and the age cutoff) can be exercised.
class Test_WPDB {
	public $posts = 'wp_posts';
	public $candidates = array();
	public $revisions = array();
	public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
	public function prepare( $q, ...$a ) {
		if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
		return array( 'query' => $q, 'args' => $a );
	}
	public function get_results( $q, $output = null ) {
		$sql = $q['query'];
		if ( str_contains( $sql, 'GROUP BY r.post_parent' ) ) {
			$cursor = 0;
			$limit  = (int) end( $q['args'] );
			foreach ( $q['args'] as $i => $arg ) { if ( is_int( $arg ) && $i === 2 ) { $cursor = $arg; } }
			$rows = array_values( array_filter( $this->candidates, fn( $r ) => $r['parent_id'] > $cursor ) );
			return array_slice( $rows, 0, $limit );
		}
		if ( str_contains( $sql, 'ORDER BY post_date_gmt DESC' ) ) {
			$parent = (int) $q['args'][0];
			return $this->revisions[ $parent ] ?? array();
		}
		return array();
	}
}
$GLOBALS['wpdb'] = new Test_WPDB();

$dir = __DIR__ . '/../includes/';
foreach ( array( 'retention-rule', 'post-types', 'settings', 'policy', 'sweep-result', 'cleaner', 'scheduler' ) as $c ) {
	require_once $dir . 'class-' . $c . '.php';
}

define( 'RVRT_PLUGIN_FILE', __DIR__ . '/../revision-retention.php' );
define( 'RVRT_VERSION', '1.0.0' );
