<?php
/**
 * LOS NOMBRES REGISTRADOS de los tipos de contenido y sus taxonomías: la
 * clave del plugin («client») con el prefijo delante («eweb_client»). Sin
 * publicar (1.2.0): hasta la 1.1.0 se registraban sin prefijo, y WordPress.org
 * lo exige (un «client» de otro plugin chocaría con el nuestro).
 *
 * DOS PREFIJOS, A PROPÓSITO: estrategia_web_ / estrategia-web para opciones,
 * metas, tablas, bloques, ganchos…, y «eweb_» solo para tipos y taxonomías,
 * porque WordPress corta un tipo en 20 caracteres y «estrategia_web_testimonial»
 * son 26. Por qué «eweb_» (David, 15-10-2026), medido en el código de todos los
 * plugins de WordPress.org (veloria.dev): «eweb_» no lo usa ninguno; «ew_» lo
 * usan 13 (Easy Watermark, Essential Widgets…) y «ewp_» 9 (ElasticPress), así
 * que se descartaron; «estweb_» no gustó.
 *
 *   clave               nombre registrado (= ruta REST /wp/v2/<nombre>)
 *   client              eweb_client
 *   client_sector       eweb_client_sector
 *   testimonial         eweb_testimonial
 *   location            eweb_location
 *   project             eweb_project
 *   project_category    eweb_project_category
 *   portfolio           eweb_portfolio
 *   portfolio_category  eweb_portfolio_category
 *
 * LA CLAVE y EL NOMBRE son dos cosas, a propósito:
 * - La CLAVE es el vocabulario del plugin: la opción estrategia_web_site
 *   (enabled_types, rewrite_slugs), el filtro estrategia_web_site_rewrite_slug,
 *   las etiquetas, el panel de campos. No cambia: la opción ya va dentro de un
 *   nombre con prefijo y renombrar sus valores sería migrar ajustes del
 *   cliente por nada.
 * - El NOMBRE es lo que ve WordPress (y los demás plugins): wp_posts.post_type,
 *   wp_term_taxonomy.taxonomy, el «postType» de una Consulta, la ruta REST,
 *   el element_type de WPML («post_eweb_client»), las opciones de Rank Math
 *   («pt_eweb_client_title»), las plantillas («single-eweb_client»).
 * Las URL públicas NO llevan ni lo uno ni lo otro: salen del slug traducido
 * (/clientes/), como siempre.
 *
 * Cómo se mantiene:
 * - EL PREFIJO VIVE SOLO AQUÍ (PREFIX). Todo lo demás lo pide con name():
 *   Content_Types, Location, Meta_Panel, el alias de Unprefixed_Names, el
 *   migrador (includes/class-prefix-migration.php) y la función pública
 *   estrategia_web_type_name() que usan los patrones de los temas.
 *   Fuera del plugin hay UNA copia, y se comprueba sola: content/semilla/
 *   tipos.mjs de cada web (la carga para si /wp/v2/types no tiene el nombre).
 * - El límite que manda: WordPress corta un tipo en 20 caracteres y una
 *   taxonomía en 32. El tipo más largo es «testimonial» (11), así que el
 *   prefijo no puede pasar de 9, guion bajo incluido. Lo mira la prueba de
 *   nivel 1 (modules/site/tests/type-names.test.mjs).
 * - Un tipo o taxonomía nuevo: su clave en KEYS (y en Settings::TYPES /
 *   REWRITE_NAMES; la misma prueba compara las listas). Nace ya con prefijo
 *   y no entra en el migrador ni en el alias: esos son para lo que existió
 *   sin él.
 * - CAMBIAR EL PREFIJO en una web ya publicada es otra migración entera
 *   (la de la 1.2.0 con otro mapa): no se toca después de publicar la 1.2.0.
 * - Caso frontera, con nombre: «location». Es también una palabra corriente
 *   (cabecera HTTP, keywords del bloque de mapa); por eso nada se busca a
 *   mano por la palabra: siempre por clave y name().
 * - La trampa: este fichero lo lleva también el migrador por stdin al
 *   servidor (desplegar.mjs), donde aún corre el plugin viejo. No puede usar
 *   nada que no esté en la 1.1.0: solo PHP y WordPress.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Type_Names {

	/** EL prefijo. Cambiarlo es esta línea (y content/semilla/tipos.mjs de cada web). */
	public const PREFIX = 'eweb_';

	/** Las claves: los cinco tipos y las tres taxonomías (= Settings::REWRITE_NAMES). */
	public const KEYS = array( 'client', 'client_sector', 'testimonial', 'location', 'project', 'project_category', 'portfolio', 'portfolio_category' );

	/** Las que son taxonomías (el resto, tipos). */
	public const TAXONOMIES = array( 'client_sector', 'project_category', 'portfolio_category' );

	/** «client» → «eweb_client». Una clave que no es nuestra sale tal cual. */
	public static function name( string $key ): string {
		return in_array( $key, self::KEYS, true ) ? self::PREFIX . $key : $key;
	}

	/** «eweb_client» → «client»; '' si no es uno de los nuestros. */
	public static function key( string $name ): string {
		if ( ! str_starts_with( $name, self::PREFIX ) ) {
			return '';
		}
		$key = substr( $name, strlen( self::PREFIX ) );
		return in_array( $key, self::KEYS, true ) ? $key : '';
	}

	/** @return array<string,string> Nombre de antes de 1.2.0 (sin prefijo) → nombre de ahora. */
	public static function renamed(): array {
		$out = array();
		foreach ( self::KEYS as $key ) {
			$out[ $key ] = self::PREFIX . $key;
		}
		return $out;
	}

	/** @return array<string,string> Solo los tipos. */
	public static function renamed_types(): array {
		return array_diff_key( self::renamed(), array_flip( self::TAXONOMIES ) );
	}

	/** @return array<string,string> Solo las taxonomías. */
	public static function renamed_taxonomies(): array {
		return array_intersect_key( self::renamed(), array_flip( self::TAXONOMIES ) );
	}
}
