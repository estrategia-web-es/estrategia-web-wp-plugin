<?php
/**
 * SOLO PRUEBAS. Lo carga un mu-plugin que escribe tests/blueprint.json en el
 * WordPress de Playground; en una web real no lo carga nadie.
 *
 * Y aun así no hace nada si no está definida ESTRATEGIA_WEB_SITE_TESTING, que solo
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
 * - Rutas estrategia-web-site-test/v1/*: ping, reset, users, page, raw-post,
 *   consent-categories (0.3.0, solo lee un filtro), consent-setup (0.5.0),
 *   modules (1.1.0: GET qué módulos cargó la petición; POST guarda la lista
 *   como la pantalla, o la borra con «active»: null), legacy-types y
 *   prefix-migration (1.2.0: siembra por SQL una web con los tipos sin
 *   prefijo y pasa el migrador; modules/site/tests/prefix-migration.test.mjs).
 * - Desde la 1.1.0 es el mu-plugin de pruebas del NÚCLEO y de los módulos
 *   que vienen de Estrategia Web Site; forms trae el suyo
 *   (modules/forms/tests/wp/). Los dos los carga tests/blueprint.json.
 * - consent-setup (0.5.0) hace de TEMA para el aviso de cookies: guarda en la
 *   opción estrategia_web_site_test_consent qué categorías declara «la web», qué
 *   devuelve el filtro del «Powered by» y si hay un
 *   <script type="text/plain" data-estrategia-web-consent="statistics"> en el pie.
 *   Es lo que en una web pone el functions.php del tema; aquí no hay tema
 *   propio (Playground usa el de por defecto). reset la borra.
 * - WebP (0.5.1): webp-support, jpeg (un JPEG hecho con GD), webp-setup (hace
 *   de tema con el filtro estrategia_web_site_webp; reset lo borra) y regenerate,
 *   que imita `wp media regenerate` (WP-CLI no está en Playground).
 * - Enlaces externos (0.5.2): external-links-setup hace de tema con el filtro
 *   estrategia_web_site_external_links (reset lo borra).
 *
 * Lo que NO hace: guardar ajustes ni crear contenido por la puerta de atrás.
 * Eso va por /wp/v2/* con usuario, que es lo que se prueba.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_SITE_TESTING' ) || true !== ESTRATEGIA_WEB_SITE_TESTING ) {
	return;
}

// No vale WPLANG: sanitize_option() la rechaza si el idioma del núcleo no está descargado.
add_filter( 'locale', static fn() => 'es_ES' );
add_filter( 'wp_is_application_passwords_available', '__return_true' );

// 0.5.0: lo que haría el tema con el aviso de cookies (ver la cabecera, consent-setup).
add_filter(
	'estrategia_web_consent_categories',
	static function ( $categories ) {
		$setup = (array) get_option( 'estrategia_web_site_test_consent', array() );
		return array_values( array_unique( array_merge( (array) $categories, (array) ( $setup['categories'] ?? array() ) ) ) );
	}
);
add_filter(
	'estrategia_web_site_consent_powered_by',
	static function ( $powered ) {
		$setup = (array) get_option( 'estrategia_web_site_test_consent', array() );
		if ( ! array_key_exists( 'powered_by', $setup ) ) {
			return $powered;
		}
		return false === $setup['powered_by'] ? false : array_merge( (array) $powered, (array) $setup['powered_by'] );
	}
);
// 0.5.1: CSS que pondría el tema (consent-setup «theme_css»). Para el hueco
// del aviso: Lavandería emite desde su theme.json «:root :where(p) { margin: 0 }»,
// que empata en especificidad con .estrategia-web-consent__text y, cargado después, gana.
add_action(
	'wp_head',
	static function () {
		$setup = (array) get_option( 'estrategia_web_site_test_consent', array() );
		if ( ! empty( $setup['theme_css'] ) ) {
			echo '<style id="estrategia-web-test-theme-css">' . wp_strip_all_tags( (string) $setup['theme_css'] ) . '</style>';
		}
	},
	100
);
// 0.5.1: lo que haría un tema que apaga el WebP (opción que pone /webp-setup).
add_filter(
	'estrategia_web_site_webp',
	static function ( $enabled ) {
		$setup = get_option( 'estrategia_web_site_test_webp', null );
		return null === $setup ? $enabled : (bool) $setup;
	}
);
// 0.5.2: lo que haría un tema que apaga los enlaces externos en pestaña nueva
// (opción que pone /external-links-setup; reset la borra).
add_filter(
	'estrategia_web_site_external_links',
	static function ( $enabled ) {
		$setup = get_option( 'estrategia_web_site_test_external_links', null );
		return null === $setup ? $enabled : (bool) $setup;
	}
);
add_action(
	'wp_footer',
	static function () {
		$setup = (array) get_option( 'estrategia_web_site_test_consent', array() );
		if ( ! empty( $setup['script'] ) ) {
			echo '<script type="text/plain" data-estrategia-web-consent="statistics">window.estrategiaWebTestStatistics = true;</script>';
		}
	}
);

add_action(
	'rest_api_init',
	static function () {
		$ns    = 'estrategia-web-site-test/v1';
		$route = static function ( string $path, string $methods, callable $cb ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $methods, 'callback' => $cb, 'permission_callback' => '__return_true' ) );
		};

		$route( '/ping', 'GET', static fn() => array( 'ok' => true ) );

		// 1.1.0, módulos: lo que se cargó en ESTA petición y lo guardado.
		// POST guarda la lista con update_option (como la pantalla), así que
		// pasa por el gancho que escribe _known; la siguiente petición ya
		// carga lo nuevo. Con «null», borra las dos opciones (por defecto).
		$route(
			'/modules',
			'GET',
			static fn() => array(
				'loaded'  => \EstrategiaWeb\Modules::loaded(),
				'blocked' => (object) \EstrategiaWeb\Modules::blocked(),
				'saved'   => get_option( \EstrategiaWeb\Modules::OPTION, null ),
				'known'   => get_option( \EstrategiaWeb\Modules::KNOWN_OPTION, null ),
				// Los que hay en disco, encendidos o no (los Default: off no salen en «loaded»).
				'available' => array_keys( \EstrategiaWeb\Modules::available() ),
			)
		);
		$route(
			'/modules',
			'POST',
			static function ( WP_REST_Request $r ) {
				$active = $r->get_param( 'active' );
				if ( null === $active ) {
					delete_option( \EstrategiaWeb\Modules::OPTION );
					delete_option( \EstrategiaWeb\Modules::KNOWN_OPTION );
				} else {
					update_option( \EstrategiaWeb\Modules::OPTION, \EstrategiaWeb\Modules::sanitize( (array) $active ) );
				}
				return array( 'ok' => true );
			}
		);

		// 0.5.1. WebP: si el editor de imágenes de este PHP sabe escribir WebP
		// (si no, la prueba no probaría nada), y un JPEG de verdad hecho con GD
		// (sin ficheros binarios en el repo), en base64.
		$route( '/webp-support', 'GET', static fn() => array( 'webp' => (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) );
		$route(
			'/jpeg',
			'GET',
			static function ( WP_REST_Request $r ) {
				$w     = max( 16, (int) ( $r['w'] ?? 1600 ) );
				$h     = max( 16, (int) ( $r['h'] ?? 1000 ) );
				$image = imagecreatetruecolor( $w, $h );
				for ( $x = 0; $x < $w; $x += 20 ) {
					imagefilledrectangle( $image, $x, 0, $x + 19, $h - 1, imagecolorallocate( $image, ( $x / 7 ) % 256, 120, 200 ) );
				}
				ob_start();
				imagejpeg( $image, null, 80 );
				return array( 'base64' => base64_encode( (string) ob_get_clean() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		);
		// 0.5.1. Hace de tema para el WebP: {"enabled": false} lo apaga con el filtro, null lo deja por defecto.
		$route(
			'/webp-setup',
			'POST',
			static function ( WP_REST_Request $r ) {
				$enabled = $r->get_json_params()['enabled'] ?? null;
				null === $enabled ? delete_option( 'estrategia_web_site_test_webp' ) : update_option( 'estrategia_web_site_test_webp', $enabled ? 1 : 0 );
				return array( 'ok' => true );
			}
		);
		// 0.5.2. Hace de tema para los enlaces externos: {"enabled": false} los apaga, null deja el por defecto.
		$route(
			'/external-links-setup',
			'POST',
			static function ( WP_REST_Request $r ) {
				$enabled = $r->get_json_params()['enabled'] ?? null;
				null === $enabled ? delete_option( 'estrategia_web_site_test_external_links' ) : update_option( 'estrategia_web_site_test_external_links', $enabled ? 1 : 0 );
				return array( 'ok' => true );
			}
		);
		// 0.5.1. Lo que hace `wp media regenerate` (WP-CLI, Media_Command):
		// borra los tamaños intermedios que dice el meta y vuelve a generar el
		// meta desde el ORIGINAL. WP-CLI no está en Playground: esto lo imita
		// para ver qué pasa con las URL que el contenido ya tiene guardadas.
		// Con skip_delete: true, como --skip-delete (no borra los viejos).
		$route(
			'/regenerate/(?P<id>\d+)',
			'POST',
			static function ( WP_REST_Request $r ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$id   = (int) $r['id'];
				$meta = wp_get_attachment_metadata( $id );
				$file = wp_get_original_image_path( $id );
				if ( empty( $r->get_json_params()['skip_delete'] ) ) {
					$dir = dirname( (string) get_attached_file( $id ) );
					foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
						wp_delete_file( $dir . '/' . $size['file'] );
					}
				}
				$new = wp_generate_attachment_metadata( $id, $file );
				wp_update_attachment_metadata( $id, $new );
				return array( 'sizes' => wp_list_pluck( (array) ( $new['sizes'] ?? array() ), 'file' ), 'file' => $new['file'] ?? '' );
			}
		);

		// 0.3.0. Lo que vería el aviso del tema padre (que aquí no está): las
		// categorías tras el filtro estrategia_web_consent_categories, partiendo de
		// la de un hijo que declara solo «maps».
		$route( '/consent-categories', 'GET', static fn() => array_values( (array) apply_filters( 'estrategia_web_consent_categories', array( 'maps' ) ) ) );

		// 0.5.0. Hace de tema para el aviso de cookies (ver la cabecera).
		$route(
			'/consent-setup',
			'POST',
			static function ( WP_REST_Request $r ) {
				update_option( 'estrategia_web_site_test_consent', (array) $r->get_json_params() );
				return array(
					'ok'         => true,
					'active'     => \EstrategiaWeb\Site\Consent::active(),
					'categories' => \EstrategiaWeb\Site\Consent::categories(),
					'revision'   => \EstrategiaWeb\Site\Consent::revision(),
				);
			}
		);

		// Deja la web como recién instalada para este plugin: sin datos, sin contenido de sus tipos.
		$route(
			'/reset',
			'POST',
			static function () {
				global $wpdb;
				delete_option( 'estrategia_web_site' );
				delete_option( 'estrategia_web_site_test_consent' );
				delete_option( 'estrategia_web_site_test_webp' );
				delete_option( 'estrategia_web_site_test_external_links' );
				delete_option( 'estrategia_web_test_suite' ); // La marca de las pruebas de forms (vuelve el es_ES).
				delete_option( 'estrategia_web_social_links' ); // Sin lista: la migración de los campos fijos vuelve a correr.
				delete_option( 'estrategia_web_modules' ); // 1.1.0: todos los módulos, como recién instalado.
				delete_option( 'estrategia_web_modules_known' );
				delete_option( 'rewrite_rules' );
				// Con y sin prefijo (1.2.0): la prueba del migrador siembra filas con los nombres viejos.
				$renamed = \EstrategiaWeb\Site\Type_Names::renamed();
				$names   = array_merge( array_keys( $renamed ), array_values( $renamed ) );
				$types   = "'" . implode( "','", array_diff( $names, \EstrategiaWeb\Site\Type_Names::TAXONOMIES, array_map( array( \EstrategiaWeb\Site\Type_Names::class, 'name' ), \EstrategiaWeb\Site\Type_Names::TAXONOMIES ) ) ) . "'";
				$taxes   = "'" . implode( "','", array_merge( \EstrategiaWeb\Site\Type_Names::TAXONOMIES, array_map( array( \EstrategiaWeb\Site\Type_Names::class, 'name' ), \EstrategiaWeb\Site\Type_Names::TAXONOMIES ) ) ) . "'";
				$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($types) OR (post_type = 'attachment' AND post_title LIKE 'prueba-%')" ); // phpcs:ignore
				foreach ( $ids as $id ) {
					wp_delete_post( (int) $id, true );
				}
				// Las taxonomías pueden no estar registradas: se borra a mano.
				$tt = $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($taxes)" ); // phpcs:ignore
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

		// 1.2.0: una web de ANTES del prefijo, sembrada por SQL (con el plugin
		// nuevo no se puede crear un «client» por REST): un cliente con su
		// sector, una sede con teléfono, y una página con la Consulta vieja.
		$route(
			'/legacy-types',
			'POST',
			static function () {
				global $wpdb;
				$insert = static function ( string $type, string $title, string $slug, string $content = '' ) use ( $wpdb ): int {
					$wpdb->insert( $wpdb->posts, array( 'post_type' => $type, 'post_title' => $title, 'post_name' => $slug, 'post_status' => 'publish', 'post_content' => $content, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ), 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ), 'post_author' => 1 ) ); // phpcs:ignore
					return (int) $wpdb->insert_id;
				};
				$client = $insert( 'client', 'Hotel de antes', 'hotel-de-antes' );
				$wpdb->insert( $wpdb->terms, array( 'name' => 'Hoteles de antes', 'slug' => 'hoteles-de-antes' ) ); // phpcs:ignore
				$term = (int) $wpdb->insert_id;
				$wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $term, 'taxonomy' => 'client_sector', 'description' => '', 'count' => 1 ) ); // phpcs:ignore
				$tt = (int) $wpdb->insert_id;
				$wpdb->insert( $wpdb->term_relationships, array( 'object_id' => $client, 'term_taxonomy_id' => $tt ) ); // phpcs:ignore
				$location = $insert( 'location', 'Sede de antes', 'sede-de-antes' );
				add_post_meta( $location, 'estrategia_web_location_phone', '971 11 22 33' );
				$query = '<!-- wp:query {"queryId":5,"query":{"perPage":10,"pages":0,"offset":0,"postType":"client","order":"asc","orderBy":"title","inherit":false,"taxQuery":{"client_sector":[' . $term . ']}}} -->'
					. '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- wp:post-terms {"term":"client_sector"} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
				$page = $insert( 'page', 'Clientes de antes', 'clientes-de-antes', $query );
				wp_cache_flush();
				return array( 'client' => $client, 'term' => $term, 'location' => $location, 'page' => $page );
			}
		);

		// El migrador del prefijo (includes/class-prefix-migration.php), en seco o de verdad.
		$route(
			'/prefix-migration',
			'POST',
			static fn( WP_REST_Request $r ) => \EstrategiaWeb\Site\Prefix_Migration::run( (bool) $r->get_param( 'dry' ) )
		);
	}
);
