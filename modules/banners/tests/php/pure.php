<?php
/**
 * Called by modules/banners/tests/pure.test.mjs with the machine's PHP (no
 * WordPress). Runs includes/class-rules.php and writes the results as JSON;
 * the assertions are in the .mjs.
 *
 * The AdRotate codes are REAL, copied from Gastronòmicament's wp_adrotate on
 * 29-10-2026 exactly as stored (entity-encoded and slashed): ads 1 (demo,
 * external image), 5 (link + image written in the code), 7 (image in the
 * «image» column, %asset% in the code), 47 (AMIC iframe) and 52 (Google Ad
 * Manager script). A new case is a new key here and its assert there.
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/includes/class-rules.php';

use EstrategiaWeb\Banners\Rules;

$ads = array(
	1  => array( '&lt;a href=\&quot;http:\/\/ajdg.net/landing.php?src=adrotate\&quot;&gt;&lt;img src=\&quot;http://ajdg.solutions/assets/banners/adrotate-468x60.jpg\&quot; /&gt;&lt;/a&gt;', '' ),
	5  => array( '&lt;a href=\&quot;https://gastronomicament.cat/ca/restaurants/destacatper/local-amb-carta-en-catala\&quot; target=\&quot;_blank\&quot;&gt;&lt;img style=\&quot;max-width: 100%; height: auto;\&quot; src=\&quot;https://gastronomicament.cat/wp-content/uploads/2020/10/Restaurants-en-catala.jpg\&quot; /&gt;&lt;/a&gt;', '' ),
	7  => array( "&lt;a href=\\&quot;https://www.instagram.com/sacomunaburger\\&quot; target=\\&quot;_blank\\&quot; rel=\\&quot;noopener noreferrer sponsored\\&quot;&gt;\n  &lt;img src=\\&quot;%asset%\\&quot; /&gt;\n&lt;/a&gt;", 'https://gastronomicament.cat/wp-content/uploads/2026/10/Sa-Comuna-a-Inca-banner-gros.jpeg' ),
	47 => array( '&lt;iframe id=\&quot;AMIC\&quot; src=\&quot;https://amic.media/continguts/campanya.php?id=2557\&quot; name=\&quot;AMIC\&quot; width=\&quot;300\&quot; height=\&quot;250\&quot; frameborder=\&quot;0\&quot;&gt;&lt;/iframe&gt;', '' ),
	52 => array( '&lt;script async src=\&quot;https://securepubads.g.doubleclick.net/tag/js/gpt.js\&quot;&gt;&lt;/script&gt;&lt;script&gt;googletag.cmd.push(function() { googletag.defineSlot(\&#039;/30029749/gastronomicament.cat/gastronomicament.cat_300x600_1\&#039;, [300, 600], \&#039;div-gpt-ad-1655239262586-0\&#039;); });&lt;/script&gt;&lt;div id=\&#039;div-gpt-ad-1655239262586-0\&#039;&gt;&lt;/div&gt;', '' ),
);
$adrotate = array();
foreach ( $ads as $id => $ad ) {
	$adrotate[ $id ] = Rules::from_adrotate( $ad[0], $ad[1] );
}

$now   = 1_800_000_000;
$state = array(
	'draft'      => Rules::state( 'draft', 0, 0, $now ),
	'no_limits'  => Rules::state( 'publish', 0, 0, $now ),
	'inside'     => Rules::state( 'publish', $now - 10, $now + 10, $now ),
	'before'     => Rules::state( 'publish', $now + 10, 0, $now ),
	'after'      => Rules::state( 'publish', 0, $now - 1, $now ),
	'on_the_end' => Rules::state( 'publish', 0, $now, $now ),
);

// pick(): with a fixed «random» sequence the result is deterministic.
$seq   = static function ( array $values ): callable {
	return static function ( int $max ) use ( &$values ): int {
		return array_shift( $values ) % $max;
	};
};
$count = array( 0 => 0, 1 => 0, 2 => 0 ); // How often each of three banners (weights 1, 1, 8) comes first over every «random» value.
for ( $n = 0; $n < 10; $n++ ) {
	$first = Rules::pick( array( 10 => 1, 11 => 1, 12 => 8 ), 1, $seq( array( $n ) ) )[0];
	++$count[ $first - 10 ];
}
$pick = array(
	'all_when_zero'   => Rules::pick( array( 3 => 5, 1 => 5, 2 => 5 ), 0, $seq( array() ) ),
	'all_when_more'   => Rules::pick( array( 3 => 5, 1 => 5 ), 5, $seq( array() ) ),
	'two_no_repeat'   => Rules::pick( array( 10 => 1, 11 => 1, 12 => 1 ), 2, $seq( array( 0, 0 ) ) ),
	'weights_1_1_8'   => $count,
	'weight_below_1'  => Rules::pick( array( 10 => 0, 11 => -3 ), 1, $seq( array( 1 ) ) ),
);

echo json_encode( array( 'adrotate' => $adrotate, 'state' => $state, 'pick' => $pick ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
