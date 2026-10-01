<?php
/**
 * SOLO PRUEBAS. Lo carga un mu-plugin que escribe tests/blueprint.json en el
 * WordPress de Playground; en una web real no lo carga nadie.
 *
 * Y aun así no hace nada si no está definida ARMAZON_SITE_TESTING, que solo
 * define ese cargador. Doble llave a propósito: estas rutas REST abren la
 * base de datos a cualquiera (permission_callback __return_true) y crean
 * usuarios con contraseña de aplicación. Al desplegar en una web, la carpeta
 * tests/ NO se sube (README, «Instalar en una web»).
 *
 * Qué da:
 * - El idioma del sitio en es_ES (filtro locale): los slugs públicos y las
 *   etiquetas se prueban en castellano, que es como salen en las webs.
 * - Contraseñas de aplicación sin HTTPS (Playground va por http): así las
 *   pruebas hablan con la API REST de verdad como admin y como autor.
 * - Rutas armazon-site-test/v1/*: ping, reset, users, page, raw-post.
 *
 * Lo que NO hace: guardar ajustes ni crear contenido por la puerta de atrás.
 * Eso va por /wp/v2/* con usuario, que es lo que se prueba.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ARMAZON_SITE_TESTING' ) || true !== ARMAZON_SITE_TESTING ) {
	return;
}

// No vale WPLANG: sanitize_option() la rechaza si el idioma del núcleo no está descargado.
add_filter( 'locale', static fn() => 'es_ES' );
add_filter( 'wp_is_application_passwords_available', '__return_true' );

add_action(
	'rest_api_init',
	static function () {
		$ns    = 'armazon-site-test/v1';
		$route = static function ( string $path, string $methods, callable $cb ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $methods, 'callback' => $cb, 'permission_callback' => '__return_true' ) );
		};

		$route( '/ping', 'GET', static fn() => array( 'ok' => true ) );

		// Deja la web como recién instalada para este plugin: sin datos, sin contenido de sus tipos.
		$route(
			'/reset',
			'POST',
			static function () {
				global $wpdb;
				delete_option( 'armazon_site' );
				delete_option( 'rewrite_rules' );
				$types = "'client','testimonial','location','project','portfolio'";
				$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($types) OR (post_type = 'attachment' AND post_title LIKE 'prueba-%')" ); // phpcs:ignore
				foreach ( $ids as $id ) {
					wp_delete_post( (int) $id, true );
				}
				// Las taxonomías pueden no estar registradas: se borra a mano.
				$tt = $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('client_sector','project_category','portfolio_category')" ); // phpcs:ignore
				if ( $tt ) {
					$in = implode( ',', array_map( 'intval', $tt ) );
					$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($in)" ); // phpcs:ignore
					$wpdb->query( "DELETE t FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} x ON x.term_id = t.term_id WHERE x.term_taxonomy_id IN ($in)" ); // phpcs:ignore
					$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($in)" ); // phpcs:ignore
				}
				return array( 'ok' => true );
			}
		);

		// Un administrador y un autor, cada uno con su contraseña de aplicación nueva.
		$route(
			'/users',
			'POST',
			static function () {
				$out = array();
				foreach ( array( 'admin' => 'administrator', 'autor-prueba' => 'author' ) as $login => $role ) {
					$user = get_user_by( 'login', $login );
					$id   = $user ? $user->ID : wp_insert_user(
						array(
							'user_login' => $login,
							'user_pass'  => wp_generate_password(),
							'user_email' => $login . '@ejemplo.test',
							'role'       => $role,
						)
					);
					\WP_Application_Passwords::delete_all_application_passwords( $id );
					[ $password ] = \WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'pruebas' ) );
					$out[ $role ] = array( 'user' => $login, 'password' => $password );
				}
				return $out;
			}
		);

		$route(
			'/page',
			'POST',
			static function ( WP_REST_Request $r ) {
				kses_remove_filters();
				$p    = $r->get_json_params();
				$slug = sanitize_title( $p['slug'] ?? 'prueba' );
				$old  = get_page_by_path( $slug );
				$id   = wp_insert_post(
					wp_slash(
						array(
							'ID'           => $old ? $old->ID : 0,
							'post_type'    => 'page',
							'post_status'  => 'publish',
							'post_title'   => $p['title'] ?? 'Prueba',
							'post_name'    => $slug,
							'post_content' => (string) $p['content'],
						)
					),
					true
				);
				return is_wp_error( $id ) ? $id : array( 'id' => $id, 'url' => get_permalink( $id ) );
			}
		);

		// La fila tal cual está en la base de datos, esté o no registrado su tipo.
		$route(
			'/raw-post/(?P<id>\d+)',
			'GET',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE ID = %d", (int) $r['id'] ), ARRAY_A ); // phpcs:ignore
				return $row ? $row : new WP_Error( 'not_found', 'no existe', array( 'status' => 404 ) );
			}
		);
	}
);
