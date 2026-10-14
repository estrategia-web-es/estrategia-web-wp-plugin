<?php
/**
 * ¿Hay otro sistema de redirecciones funcionando en la web? Si lo hay, la
 * pantalla lo avisa arriba: dos sistemas a la vez se pisan y nadie sabe cuál
 * mandó al visitante adónde.
 *
 * Cómo se mantiene:
 * - Un plugin nuevo que detectar: una entrada más en detect(), mirando una
 *   constante o clase que el plugin define SIEMPRE que está activo (no su
 *   carpeta: puede estar subido y desactivado). Si es de terceros con un
 *   módulo que se apaga (Rank Math), mirar también que el módulo esté
 *   encendido, o el aviso saldrá en webs donde no pasa nada.
 * - Caso frontera con nombre: Rank Math en Lavandería. La empresa de SEO lo
 *   usa con su módulo de redirecciones, y por eso este módulo nace apagado
 *   (Default: off) y allí se queda apagado. Rank Math evalúa sus reglas en
 *   el gancho «wp», antes que este (template_redirect): si los dos tienen
 *   regla para lo mismo, gana Rank Math.
 * - La trampa: Rank Math guarda sus módulos encendidos en la opción
 *   rank_math_modules (lista de slugs); el de redirecciones es
 *   «redirections», con -ions. Con Rank Math instalado pero ese módulo
 *   apagado, no hay conflicto.
 * - Filtro estrategia_web_redirects_conflicts para que una web añada el suyo.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Conflicts {

	/** @return string[] Nombres de los sistemas de redirecciones activos además de este. */
	public static function detect(): array {
		$found = array();
		if ( defined( 'RANK_MATH_VERSION' ) && in_array( 'redirections', (array) get_option( 'rank_math_modules', array() ), true ) ) {
			$found[] = 'Rank Math';
		}
		if ( defined( 'REDIRECTION_VERSION' ) ) {
			$found[] = 'Redirection';
		}
		if ( defined( 'WPSEO_PREMIUM_VERSION' ) ) {
			$found[] = 'Yoast SEO Premium';
		}
		if ( class_exists( 'SRM_Redirect' ) || defined( 'SRM_VERSION' ) ) {
			$found[] = 'Safe Redirect Manager';
		}
		if ( defined( 'AIOSEO_VERSION' ) && function_exists( 'aioseo' ) && class_exists( 'AIOSEO\Plugin\Pro\Redirects\Redirects' ) ) {
			$found[] = 'All in One SEO Pro';
		}
		/**
		 * Otros sistemas de redirecciones activos en la web.
		 *
		 * @param string[] $found Nombres para el aviso.
		 */
		return array_values( array_unique( (array) apply_filters( 'estrategia_web_redirects_conflicts', $found ) ) );
	}
}
