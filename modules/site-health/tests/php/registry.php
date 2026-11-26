<?php
/**
 * Called by modules/site-health/tests/registry.test.mjs with the machine's
 * PHP (no WordPress, stubs.php). Writes as JSON: the real checks the
 * registry loads and the files in checks/, what it loads from fixtures/
 * (one valid file, three broken ones), what it adds to site_status_tests
 * in Spanish and in English, a formatted verdict with HTML in it, and the
 * result of a check whose gather() throws. The assertions are in the .mjs.
 */

require __DIR__ . '/stubs.php';

use EstrategiaWeb\SiteHealth\Registry;

Registry::hook();
$hooked = array_keys( $GLOBALS['stub']['filters'] );

$real  = Registry::checks();
$files = array_map( static fn( $f ) => basename( $f, '.php' ), glob( dirname( __DIR__, 2 ) . '/checks/*.php' ) );

$shape = static function ( array $tests ): array {
	$out = array();
	foreach ( array( 'direct', 'async' ) as $kind ) {
		foreach ( $tests[ $kind ] ?? array() as $key => $t ) {
			$out[ $kind ][ $key ] = array(
				'label'     => $t['label'],
				'test'      => is_string( $t['test'] ) ? $t['test'] : ( is_callable( $t['test'] ) ? 'callable' : 'other' ),
				'has_rest'  => $t['has_rest'] ?? null,
				'cron_test' => isset( $t['async_direct_test'] ) && is_callable( $t['async_direct_test'] ),
			);
		}
	}
	return $out;
};

$core = array( 'direct' => array( 'php_version' => array( 'label' => 'PHP', 'test' => 'php_version' ) ), 'async' => array() );
$es   = $shape( $GLOBALS['stub']['filters']['site_status_tests']( $core ) );
$GLOBALS['stub']['locale']     = 'en_US';
$GLOBALS['stub']['post_types'] = array( 'eweb_location' );
$en   = $shape( Registry::tests( $core, $real ) );

$throws = array(
	'id'     => 'throws',
	'async'  => false,
	'title'  => static fn() => 'Thrower',
	'gather' => static function (): array {
		throw new RuntimeException( 'unexpected <option> shape' );
	},
	'judge'  => static fn( array $d ) => array( 'status' => 'good' ),
);

echo json_encode(
	array(
		'hooked'    => $hooked,
		'real'      => array_keys( $real ),
		'files'     => $files,
		'fixtures'  => array_keys( Registry::checks( __DIR__ . '/fixtures' ) ),
		'es'        => $es,
		'en'        => $en,
		'formatted' => Registry::format(
			'company-details',
			array(
				'status'      => 'nonsense',
				'label'       => 'Label',
				'description' => 'Why <script>alert(1)</script> & more',
				'items'       => array( 'A <b>', '', 'B' ),
				'actions'     => array(
					array( 'path' => 'admin.php?page=x&tab=y', 'text' => 'Go "there"' ),
					array( 'url' => 'https://dmarc.org/', 'text' => 'Out' ),
					array( 'text' => 'Do it in the DNS' ),
				),
			)
		),
		'good'      => Registry::format( 'dmarc', array( 'status' => 'good', 'label' => 'Ok', 'description' => 'Fine' ) ),
		'thrown'    => Registry::result( $throws ),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
