<?php
/**
 * Funciones globales del módulo site, para los TEMAS (sin espacio de nombres,
 * con prefijo estrategia_web_). Contrato: README, «Nombres públicos».
 *
 * Cómo se mantiene: aquí solo entra lo que un tema necesita llamar desde un
 * patrón o una plantilla PHP; la lógica sigue en sus clases. Un tema que las
 * use las protege con function_exists(): sin el plugin, el patrón tiene que
 * seguir cargando (vacío, pero sin un error fatal).
 */

defined( 'ABSPATH' ) || exit;

/**
 * El nombre REGISTRADO de un tipo o taxonomía del plugin a partir de su clave
 * (1.2.0): estrategia_web_type_name( 'client' ) → 'ew_client'. Para el
 * "postType" de una Consulta en un patrón:
 *
 *     "postType":"<?php echo esc_attr( function_exists( 'estrategia_web_type_name' ) ? estrategia_web_type_name( 'client' ) : 'ew_client' ); ?>"
 *
 * Una clave que no es del plugin («page») sale tal cual.
 */
function estrategia_web_type_name( string $key ): string {
	return \EstrategiaWeb\Site\Type_Names::name( $key );
}
