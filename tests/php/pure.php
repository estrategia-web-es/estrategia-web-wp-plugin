<?php
/**
 * Lo llama tests/pure.test.mjs con el PHP de la máquina (sin WordPress).
 * Ejecuta las clases puras con casos fijos y escribe el resultado en JSON;
 * las aserciones están en el .mjs.
 *
 * Cómo se mantiene: un caso nuevo es una clave nueva aquí y su assert allí.
 */

require __DIR__ . '/../../includes/class-links.php';
require __DIR__ . '/../../includes/class-svg-sanitizer.php';
require __DIR__ . '/../../includes/class-external-links.php';

// class-webp.php (0.5.4) lleva la guarda de ABSPATH y usa apply_filters: lo mínimo para cargarla sin WordPress.
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['pure_filters'] = array();
function apply_filters( string $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['pure_filters'] ) ? $GLOBALS['pure_filters'][ $hook ] : $value;
}
require __DIR__ . '/../../includes/class-webp.php';
use Armazon\Site\Webp;
$webp_quality = array(
	'webp'  => Webp::quality( 82, 'image/webp' ),
	'jpeg'  => Webp::quality( 82, 'image/jpeg' ),
	'png'   => Webp::quality( 90, 'image/png' ),
);
$GLOBALS['pure_filters']['armazon_site_webp_quality'] = 60;
$webp_quality['filtro'] = Webp::quality( 82, 'image/webp' );
$GLOBALS['pure_filters']['armazon_site_webp_quality'] = 0;
$webp_quality['cero'] = Webp::quality( 82, 'image/webp' );
$GLOBALS['pure_filters']['armazon_site_webp_quality'] = 140;
$webp_quality['tope'] = Webp::quality( 82, 'image/webp' );
$GLOBALS['pure_filters'] = array();

use Armazon\Site\External_Links;
use Armazon\Site\Links;
use Armazon\Site\Svg_Sanitizer;

$hostile = <<<'SVG'
<?xml version="1.0"?>
<?xml-stylesheet href="https://evil.test/x.css"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" width="120" height="60" viewBox="0 0 120 60" onload="alert(1)">
  <!-- comentario -->
  <script>alert(1)</script>
  <sodipodi:namedview id="nv"/>
  <style>@import url(https://evil.test/a.css); .a{fill:red}</style>
  <style>.b{fill:url(#grad);background:url(https://evil.test/t.png)}</style>
  <defs><linearGradient id="grad"><stop offset="0" stop-color="#fff"/></linearGradient></defs>
  <a href="javascript:alert(1)"><rect width="10" height="10"/></a>
  <foreignObject><div xmlns="http://www.w3.org/1999/xhtml">hola</div></foreignObject>
  <path d="M0 0h10" fill="url(#grad)" onclick="alert(1)" style="fill:red;behavior:url(x.htc)"/>
  <rect width="5" height="5" fill="url(https://evil.test/p)"/>
  <use xlink:href="#grad"/>
  <use href="https://evil.test/sprite.svg#x"/>
  <image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>
  <image href="https://evil.test/track.png" width="1" height="1"/>
  <circle cx="5" cy="5" r="4" fill="j&#x61;vascript:alert(1)"/>
  <text x="0" y="50" font-family="Arial">Logo &amp; Co</text>
</svg>
SVG;

$clean = Svg_Sanitizer::sanitize( $hostile );

$xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';

echo json_encode(
	array(
		'tel_local'          => Links::tel( '971 12 34 56' ),
		'tel_plus'           => Links::tel( '+44 20 7946 0000' ),
		'tel_00'             => Links::tel( '0044 20 7946 0000' ),
		'tel_empty'          => Links::tel( '' ),
		'mailto'             => Links::mailto( ' hola@ejemplo.test ' ),
		'wa'                 => Links::whatsapp( '34600111222', 'Hola, quería información' ),
		'wa_local'           => Links::whatsapp( '600 111 222' ),
		'wa_empty'           => Links::whatsapp( '', 'x' ),
		'clean'              => $clean,
		'xxe'                => Svg_Sanitizer::sanitize( $xxe ),
		'not_svg'            => Svg_Sanitizer::sanitize( '<html><body>hola</body></html>' ),
		'not_xml'            => Svg_Sanitizer::sanitize( 'no es xml <' ),
		'dims_attrs'         => Svg_Sanitizer::dimensions( '<svg xmlns="http://www.w3.org/2000/svg" width="120px" height="60" viewBox="0 0 10 10"/>' ),
		'dims_viewbox'       => Svg_Sanitizer::dimensions( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300.5 150"/>' ),
		'dims_one_side'      => Svg_Sanitizer::dimensions( '<svg xmlns="http://www.w3.org/2000/svg" width="200" viewBox="0 0 100 50"/>' ),
		'dims_percent'       => Svg_Sanitizer::dimensions( '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 40 20"/>' ),
		'dims_none'          => Svg_Sanitizer::dimensions( '<svg xmlns="http://www.w3.org/2000/svg"/>' ),
		// 0.5.2. Enlaces externos: href => ¿externo?, con la web en www.web.test.
		'external'           => array_combine(
			$hrefs = array(
				'https://wa.me/34600111222',
				'https://api.whatsapp.com/send?phone=34600111222',
				'http://maps.google.com/?q=x',
				'//facebook.com/x',
				'HTTPS://WEB-VIEJA.TEST/',
				'https://otroweb.test/',
				'https://web.test.evil.test/',
				'https://www.web.test/contacto/',
				'https://web.test/contacto/',
				'https://WWW.Web.Test./x',
				'https://tienda.web.test/',
				'//web.test/x',
				'/contacto/',
				'contacto/',
				'#arriba',
				'?p=1',
				'',
				'mailto:hola@web.test',
				'tel:+34971123456',
				'sms:+34600111222',
				'javascript:alert(1)',
				'whatsapp://send?phone=34600',
			),
			array_map( static fn( $h ) => External_Links::is_external( $h, 'www.web.test' ), $hrefs )
		),
		'rel_empty'          => External_Links::add_rel( '', 'noopener' ),
		'rel_keep'           => External_Links::add_rel( ' nofollow  sponsored ', 'noopener' ),
		'rel_dup'            => External_Links::add_rel( 'NoOpener nofollow', 'noopener' ),
		'webp_quality'       => $webp_quality,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
