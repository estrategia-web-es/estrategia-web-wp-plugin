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
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
