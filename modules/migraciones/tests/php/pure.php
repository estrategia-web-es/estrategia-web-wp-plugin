<?php
/**
 * Called by pure.test.mjs with the machine's PHP (no WordPress). Runs
 * includes/class-runner.php against stubs (options in an array) and fixture
 * migrations written to a temp folder; prints what happened as JSON.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts']    = array();
$GLOBALS['actions'] = array();
function add_option( $k, $v ) {
	if ( array_key_exists( $k, $GLOBALS['opts'] ) ) {
		return false;
	}
	$GLOBALS['opts'][ $k ] = $v;
	return true;
}
function get_option( $k, $d = false ) {
	return $GLOBALS['opts'][ $k ] ?? $d;
}
function update_option( $k, $v ) {
	$GLOBALS['opts'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['opts'][ $k ] );
}
function do_action( $h, ...$a ) {
	$GLOBALS['actions'][] = $h . ':' . $a[0] . ':' . $a[1];
}
function sg_cachepress_purge_cache() {
	$GLOBALS['actions'][] = 'sg';
}

require dirname( __DIR__, 2 ) . '/includes/class-migracion.php';
require dirname( __DIR__, 2 ) . '/includes/class-runner.php';
use EstrategiaWeb\Migraciones\Runner;

$dir = sys_get_temp_dir() . '/migr-' . getmypid();
mkdir( $dir );
$mk = static function ( $name, $body ) use ( $dir ) {
	file_put_contents( "$dir/$name.php", "<?php return new class extends \\EstrategiaWeb\\Migraciones\\Migracion { public function aplicar() { $body } };" );
};
$mk( '2026-11-27-segunda', 'return "dos";' );
$mk( '2026-11-26-primera', 'return "uno";' );
file_put_contents( "$dir/notas.php", '<?php die("no");' ); // not a migration: bad name.

$files = Runner::files( $dir );
$out   = array( 'orden' => array_keys( $files ) );

$out['seco']  = Runner::run( true, $files );
$out['nada_escrito'] = $GLOBALS['opts'] === array();
$out['uno']   = Runner::run( false, $files );
$out['otra_vez'] = Runner::run( false, $files );
$out['hecho'] = array_map( static fn( $d ) => array( $d['resultado'], $d['detalle'] ), Runner::done() );

// A failing one stops the queue.
$GLOBALS['opts'] = array();
$GLOBALS['actions'] = array();
$mk( '2026-11-26-primera', 'return "uno";' );
$mk( '2026-11-27-segunda', 'throw new \RuntimeException("rota");' );
$mk( '2026-11-28-tercera', 'return "tres";' );
$files = Runner::files( $dir );
$out['falla'] = Runner::run( false, $files );
$out['falla_hecho'] = array_map( static fn( $d ) => $d['resultado'], Runner::done() );
$out['falla_pendientes'] = array_keys( Runner::pending( $files, Runner::done() ) );
$out['falla_acciones'] = $GLOBALS['actions'];

// Lock held by someone else: nothing runs; a stale lock is replaced.
$GLOBALS['opts'] = array( Runner::OPT_LOCK => time() );
$out['bloqueada'] = Runner::run( false, $files )['status'];
$GLOBALS['opts'] = array( Runner::OPT_LOCK => time() - 1000 );
$out['caducada'] = Runner::run( false, array( '2026-11-26-primera' => $files['2026-11-26-primera'] ) )['status'];
$out['lock_liberado'] = ! isset( $GLOBALS['opts'][ Runner::OPT_LOCK ] );

array_map( 'unlink', glob( "$dir/*.php" ) );
rmdir( $dir );
echo json_encode( $out );
