<?php
/**
 * Lo que pasa en cada petición de la web pública.
 *
 * CUÁNDO SE MIRA LA TABLA (decidido y medido, 14-10-2026): solo cuando la
 * petición va a dar 404. En template_redirect con prioridad 1, que corre
 * después de que WordPress decida el 404 (WP::handle_404, dentro de wp()) y
 * ANTES de redirect_canonical (prioridad 10), que si no adivinaría una
 * página parecida (redirect_guess_404_permalink) y se adelantaría a la regla.
 *
 * - Una página que existe: CERO consultas a la tabla (lo comprueba
 *   tests/server.test.mjs contando las consultas de la petición).
 * - Un 404: UNA consulta por el índice único (source_hash IN (…)), y si casa
 *   una escritura más (el contador). Si no casa y el registro de 404 está
 *   encendido, una o dos más en el registro.
 *
 * La consecuencia, y es el caso frontera con nombre: una regla cuyo ORIGEN
 * EXISTE en la web no actúa (/contacto/ → /contacto-nuevo/ con la página
 * /contacto/ publicada). Es lo que se quiere al migrar una web (no tapar una
 * página viva por una regla vieja), y la pantalla lo avisa al guardar. Para
 * quitar una página viva: se borra o se pasa a borrador, y entonces la regla
 * entra sola.
 *
 * Un 410 no redirige: devuelve el código 410 y deja que el tema pinte su
 * página de «no encontrado» (is_404 sigue siendo cierto), así el visitante
 * ve la web y no una página en blanco.
 *
 * El destino se construye con la opción «home» CRUDA, no con home_url():
 * WPML filtra home_url() con el idioma de la petición y un /en/… acabaría
 * en /en/en/…. Las rutas del CSV ya llevan su idioma.
 *
 * La query de la petición NO pasa al destino (/viejo/?utm=x → /nuevo/):
 * el destino es lo que dice la regla, nada más. Si un día hace falta
 * conservar las utm, es un filtro: estrategia_web_redirects_target.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Runtime {

	public static function boot(): void {
		add_action( 'template_redirect', array( self::class, 'on_404' ), 1 );
	}

	public static function on_404(): void {
		if ( ! is_404() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se normaliza en Rules::split y nunca se pinta.
		$rule = Store::match( $uri );
		if ( ! $rule ) {
			if ( Settings::get()['log_404'] ) {
				Store::log_404( $uri );
			}
			return;
		}
		Store::hit( $rule['id'] );

		if ( 410 === $rule['type'] ) {
			status_header( 410 );
			nocache_headers();
			return; // El tema pinta su 404 con el código 410.
		}

		$target = $rule['target'];
		if ( '/' === $target[0] && 0 !== strpos( $target, '//' ) ) {
			$target = untrailingslashit( (string) get_option( 'home' ) ) . $target;
		}
		/**
		 * El destino de una redirección, justo antes de mandarla.
		 *
		 * @param string $target URL absoluta.
		 * @param array  $rule   id, source, target, type.
		 * @param string $uri    La petición tal como llegó.
		 */
		$target = (string) apply_filters( 'estrategia_web_redirects_target', $target, $rule, $uri );
		if ( wp_redirect( $target, $rule['type'], 'Estrategia Web' ) ) { // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- el destino lo escribe el administrador y puede ser otra web.
			exit;
		}
	}
}
