<?php
/**
 * Called by modules/cache-purge/tests/pure.test.mjs with the machine's PHP (no
 * WordPress). Runs includes/class-purger.php against stubs and writes what
 * happened as JSON; the assertions are in the .mjs.
 *
 * The stubs: two posts (10 published, 11 a draft), a user who can edit only
 * when $GLOBALS['can'] is true, and TWO cache plugins «installed» (SiteGround
 * and WP Rocket: their functions exist and record their calls). W3TC, Super
 * Cache and LiteSpeed are not, so their adapters must not run.
 *
 *   php pure.php        a web request
 *   php pure.php cli    the same, with WP_CLI defined (it cannot be undefined: a separate run)
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( 'cli' === ( $argv[1] ?? '' ) ) {
	define( 'WP_CLI', true );
}

$GLOBALS['can']   = false;
$GLOBALS['calls'] = array();

function add_action( ...$a ) {}
function do_action( $hook, ...$a ) {
	$GLOBALS['calls'][] = array_merge( array( 'action:' . $hook ), $a );
}
function wp_doing_cron() {
	return false;
}
function current_user_can( $cap, $id ) {
	return $GLOBALS['can'];
}
function get_post_status( $id ) {
	return 10 === $id ? 'publish' : 'draft';
}
function get_permalink( $id ) {
	return "https://example.test/ca/post-$id";
}
function sg_cachepress_purge_cache( $url ) {
	$GLOBALS['calls'][] = array( 'siteground', $url );
}
function rocket_clean_post( $id ) {
	$GLOBALS['calls'][] = array( 'wp-rocket', $id );
}

require dirname( __DIR__, 2 ) . '/includes/class-purger.php';

use EstrategiaWeb\CachePurge\Purger;

$out = array();
$run = static function ( string $name, callable $writes ) use ( &$out ) {
	$GLOBALS['calls'] = array();
	$writes();
	Purger::flush();
	$out[ $name ] = $GLOBALS['calls'];
};

// A visitor's request writes a field of a published post (a view counter).
$run(
	'visitor',
	static function () {
		$GLOBALS['can'] = false;
		Purger::on_meta( 1, 10, 'post_views' );
	}
);
// An editor (or a script running as one) writes three fields of the same post.
$run(
	'editor',
	static function () {
		$GLOBALS['can'] = true;
		Purger::on_meta( 1, 10, 'gallery' );
		Purger::on_meta( 2, 10, 'gallery' );
		Purger::on_meta( array( 3 ), 10, 'home_date' );
	}
);
// WordPress's own bookkeeping, and a draft.
$run(
	'bookkeeping',
	static function () {
		$GLOBALS['can'] = true;
		Purger::on_meta( 1, 10, '_edit_lock' );
		Purger::on_meta( 1, 10, '_edit_last' );
	}
);
$run(
	'draft',
	static function () {
		$GLOBALS['can'] = true;
		Purger::on_meta( 1, 11, 'gallery' );
	}
);
// Nobody logged in: only WP-CLI counts (the «cli» run).
$run(
	'nobody',
	static function () {
		$GLOBALS['can'] = false;
		Purger::on_meta( 1, 10, 'gallery' );
	}
);

echo json_encode( $out );
