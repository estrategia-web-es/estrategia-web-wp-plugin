<?php
/**
 * SOLO PRUEBAS. Lo carga un mu-plugin que escribe tests/blueprint.json en el
 * WordPress de Playground; en una web real no lo carga nadie.
 *
 * Y aun así no hace nada si no está definida ESTRATEGIA_WEB_FORMS_TESTING, que solo
 * define ese cargador. Es la doble llave a propósito: estas rutas REST abren
 * la base de datos a cualquiera (permission_callback __return_true), y este
 * fichero viaja dentro de la carpeta del plugin. Al desplegar en una web, la
 * carpeta tests/ NO se sube (README, «Instalar en una web»).
 *
 * Qué da:
 * - Intercepta wp_mail (filtro pre_wp_mail): no sale ningún correo y cada uno
 *   queda guardado en la opción estrategia_web_forms_test_mails para que la prueba
 *   lo lea. Un destinatario que contenga «fail@» simula un fallo de envío.
 * - Rutas estrategia-web-forms-test/v1/*: reset, settings, page (también un bloque
 *   sincronizado con type=wp_block), post/<id> (el post_content guardado),
 *   mails, submissions, locale, backdate, purge.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_FORMS_TESTING' ) || true !== ESTRATEGIA_WEB_FORMS_TESTING ) {
	return;
}

const ESTRATEGIA_WEB_FORMS_TEST_MAILS = 'estrategia_web_forms_test_mails';

// El idioma del sitio para las pruebas. No vale WPLANG: sanitize_option() la
// rechaza si el idioma del núcleo no está descargado, y Playground no lo tiene.
//
// Desde la 1.1.0 comparte WordPress con las pruebas del resto de módulos, y
// el mu-plugin de pruebas del núcleo (tests/wp/) pone es_ES para TODO. Estas
// pruebas esperan inglés salvo que pidan otro idioma por /locale, así que su
// reset() marca «estrategia_web_test_suite = forms» y aquí, con prioridad 20
// (después del es_ES), se vuelve a en_US. El reset del núcleo quita la marca.
add_filter(
	'locale',
	static function ( $locale ) {
		$forced = get_option( 'estrategia_web_forms_test_locale' );
		if ( $forced ) {
			return $forced;
		}
		return 'forms' === get_option( 'estrategia_web_test_suite' ) ? 'en_US' : $locale;
	},
	20
);

// El texto plano de cada aviso no llega a pre_wp_mail (va como AltBody): se recoge aquí.
$GLOBALS['estrategia_web_forms_test_text'] = '';
add_filter(
	'estrategia_web_forms_email',
	static function ( $mail ) {
		$GLOBALS['estrategia_web_forms_test_text'] = $mail['text'];
		return $mail;
	},
	999
);

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$to    = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
		$mails = get_option( ESTRATEGIA_WEB_FORMS_TEST_MAILS, array() );
		$fail  = (bool) array_filter( $to, static fn( $t ) => str_contains( (string) $t, 'fail@' ) );
		$mails[] = array(
			'to'      => array_values( $to ),
			'subject' => $atts['subject'],
			'html'    => $atts['message'],
			'text'    => $GLOBALS['estrategia_web_forms_test_text'],
			'headers' => $atts['headers'],
			'sent'    => ! $fail,
		);
		update_option( ESTRATEGIA_WEB_FORMS_TEST_MAILS, $mails, false );
		return ! $fail;
	},
	10,
	2
);

add_action(
	'rest_api_init',
	static function () {
		$ns    = 'estrategia-web-forms-test/v1';
		$open  = '__return_true';
		$route = static function ( string $path, string $methods, callable $cb ) use ( $ns, $open ) {
			register_rest_route( $ns, $path, array( 'methods' => $methods, 'callback' => $cb, 'permission_callback' => $open ) );
		};

		$route(
			'/reset',
			'POST',
			static function () {
				global $wpdb;
				$wpdb->query( 'DELETE FROM ' . \EstrategiaWeb\Forms\Migrations::submissions_table() ); // phpcs:ignore
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%estrategia_web_forms_%'" ); // phpcs:ignore
				delete_option( ESTRATEGIA_WEB_FORMS_TEST_MAILS );
				update_option( 'estrategia_web_forms_settings', array_merge( \EstrategiaWeb\Forms\Settings::DEFAULTS, array( 'min_seconds' => 1, 'rate_limit' => 0 ) ) );
				delete_option( 'estrategia_web_forms_test_locale' );
				update_option( 'estrategia_web_test_suite', 'forms' ); // Ver el filtro «locale» de arriba.
				return array( 'ok' => true );
			}
		);

		$route(
			'/settings',
			'POST',
			static function ( WP_REST_Request $r ) {
				$current = get_option( 'estrategia_web_forms_settings', array() );
				update_option( 'estrategia_web_forms_settings', array_merge( (array) $current, (array) $r->get_json_params() ) );
				return \EstrategiaWeb\Forms\Settings::all();
			}
		);

		$route(
			'/page',
			'POST',
			static function ( WP_REST_Request $r ) {
				kses_remove_filters();
				$p    = $r->get_json_params();
				$type = 'wp_block' === ( $p['type'] ?? '' ) ? 'wp_block' : 'page';
				$slug = sanitize_title( $p['slug'] ?? 'formulario' );
				$old  = get_page_by_path( $slug, OBJECT, $type );
				$id   = wp_insert_post(
					wp_slash(
						array(
							'ID'           => $old ? $old->ID : 0,
							'post_type'    => $type,
							'post_status'  => 'publish',
							'post_title'   => $p['title'] ?? 'Formulario',
							'post_name'    => $slug,
							'post_content' => (string) $p['content'],
						)
					),
					true
				);
				return is_wp_error( $id ) ? $id : array( 'id' => $id, 'url' => get_permalink( $id ) );
			}
		);

		$route( '/post/(?P<id>\d+)', 'GET', static fn( WP_REST_Request $r ) => array( 'content' => (string) get_post_field( 'post_content', (int) $r['id'], 'raw' ) ) );

		$route( '/mails', 'GET', static fn() => get_option( ESTRATEGIA_WEB_FORMS_TEST_MAILS, array() ) );

		$route( '/submissions', 'GET', static fn() => \EstrategiaWeb\Forms\Submissions::query( '', 0 )['rows'] );

		$route(
			'/locale',
			'POST',
			static function ( WP_REST_Request $r ) {
				update_option( 'estrategia_web_forms_test_locale', sanitize_text_field( (string) $r->get_param( 'locale' ) ) );
				return array( 'locale' => get_option( 'estrategia_web_forms_test_locale' ) );
			}
		);

		$route(
			'/backdate',
			'POST',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				$days = (int) $r->get_param( 'days' );
				$id   = (int) $r->get_param( 'id' );
				$wpdb->update( \EstrategiaWeb\Forms\Migrations::submissions_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
				return array( 'ok' => true );
			}
		);

		$route(
			'/purge',
			'POST',
			static function () {
				do_action( \EstrategiaWeb\Forms\Cron::HOOK );
				return array(
					'scheduled'  => (bool) wp_next_scheduled( \EstrategiaWeb\Forms\Cron::HOOK ),
					'last_purge' => get_option( 'estrategia_web_forms_last_purge' ),
				);
			}
		);
	}
);
