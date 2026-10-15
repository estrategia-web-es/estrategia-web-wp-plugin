<?php
/**
 * Module Name: Social networks
 * Description: Free, ordered list of social network links (name, link, icon from an included collection or your own SVG) and a block that shows them.
 * Default: on
 * Requires: site
 *
 * Tres piezas:
 * - La lista: opción estrategia_web_social_links (includes/class-links.php,
 *   ahí está su forma, el saneado, la migración de las webs que ya tenían
 *   redes en los campos fijos de «Datos de la empresa» y el alias
 *   `social.<red>` de Company::value() hasta la 2.0.0).
 * - La pantalla «Estrategia Web → Redes sociales» (includes/class-admin.php
 *   y assets/admin.js): una fila por red, ordenable con botones.
 * - El bloque estrategia-web/social-links (blocks/social-links/render.php):
 *   solo iconos o icono y nombre, tres tamaños, color del texto.
 * Los iconos: 17 de la colección (icons/, créditos en icons/CREDITS.txt:
 * Simple Icons, CC0, y Bootstrap Icons, MIT) o un SVG de la mediateca,
 * saneado otra vez al pintarlo (includes/class-icons.php).
 *
 * Por qué Default: on: no cambia nada visible por sí solo. El bloque no está
 * en ninguna página hasta que alguien lo pone, y la pantalla es una entrada
 * más en el menú. Al desplegarlo en una web que ya existe (Lavandería) se
 * enciende solo (ver el caso frontera de includes/class-modules.php) y no se
 * nota hasta que se use.
 *
 * Por qué Requires: site: el saneado de SVG (Svg_Sanitizer) y el nombre de
 * la empresa para «Instagram de <Empresa>» son de ese módulo.
 *
 * Con WPML: el nombre y el enlace de cada red se traducen como textos de
 * administración (wpml-config.xml, <admin-texts>). Sin probar con WPML.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien añada una red a la colección (icons/ + CREDITS +
 *   Icons::labels() y HOSTS) o un campo por red (Links + pantalla + XML).
 * - Criterio para que una red entre en la colección: que la pida una web de
 *   verdad y que haya un icono con licencia compatible con GPL (CC0, MIT,
 *   GPL). Una red que solo usa un cliente va como SVG subido, no aquí.
 * - Caso frontera con nombre: Google. «Google Maps» (la ficha en el mapa) y
 *   «Google (Business Profile)» (las reseñas) son dos iconos; el enlace
 *   google.com/maps… adivina el de mapas, el resto de google.* el otro.
 * - La trampa al copiar iconos nuevos: los SVG de Simple Icons traen <title>
 *   y role="img"; dentro de un enlace con su nombre en texto oculto, un
 *   lector de pantalla puede leer el nombre dos veces. Se quitan al
 *   copiarlos (solo viewBox y <path>) y el <svg> sale con aria-hidden.
 *
 * Pruebas: modules/social/tests/ (social: nivel 2; social-admin: nivel 3).
 */

namespace EstrategiaWeb\Social;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-icons.php';
require_once __DIR__ . '/includes/class-links.php';

add_action( 'init', array( Links::class, 'register' ) );
// Después de register: los seis campos fijos de «Datos de la empresa» → la lista, una vez (ver Links).
add_action( 'init', array( Links::class, 'migrate' ), 11 );
add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/social-links' ) );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-admin.php';
	Admin::boot();
}
