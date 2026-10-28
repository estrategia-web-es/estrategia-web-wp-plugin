<?php
/**
 * TESTS ONLY. Loaded by a mu-plugin that tests/blueprint.json writes into the
 * Playground WordPress; on a real site nobody loads it.
 *
 * And even then it does nothing unless ESTRATEGIA_WEB_FORMS_TESTING is defined, which only
 * that loader defines. The double lock is deliberate: these REST routes open
 * the database to anyone (permission_callback __return_true), and this
 * file travels inside the plugin folder. When deploying to a site, the
 * tests/ folder is NOT uploaded (README, «Installing on a site»).
 *
 * What it provides:
 * - Intercepts wp_mail (pre_wp_mail filter): no email goes out and each one
 *   is stored in an estrategia_web_forms_test_mails_<unique> option (one per
 *   email: see the filter) for the test to read. A recipient containing
 *   «fail@» simulates a sending failure.
 * - Routes estrategia-web-forms-test/v1/*: reset (with the core reset and the
 *   file's whole seed, in one request: see the route), state (mails and log),
 *   settings, page (also a synced block with type=wp_block, or an existing
 *   post by id), post/<id> (the saved post_content), posts?ids= (contents and
 *   migration backups), mails, submissions, locale, backdate, purge (with an
 *   optional backdate), strings (several WPML /string calls).
 * How to maintain it (28-10-2026): a request to the test WordPress costs
 * ~1,3 s (3-6 s with WPML) whatever it does, so a test that needs N things
 * set up asks for them in ONE call — a new key in /reset's body, a route that
 * answers several things at once — never N calls in its before().
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_FORMS_TESTING' ) || true !== ESTRATEGIA_WEB_FORMS_TESTING ) {
	return;
}

const ESTRATEGIA_WEB_FORMS_TEST_MAILS = 'estrategia_web_forms_test_mails';

// The site language for the tests. WPLANG does not work: sanitize_option()
// rejects it if the core language pack is not downloaded, and Playground does not have it.
//
// Since 1.1.0 it shares WordPress with the other modules' tests, and
// the core test mu-plugin (tests/wp/) sets es_ES for EVERYTHING. These
// tests expect English unless they ask for another language via /locale, so their
// reset() sets «estrategia_web_test_suite = forms» and here, with priority 20
// (after the es_ES), it goes back to en_US. The core reset removes the mark.
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

// Each notification's plain text does not reach pre_wp_mail (it goes as AltBody): it is collected here.
$GLOBALS['estrategia_web_forms_test_text'] = '';
add_filter(
	'estrategia_web_forms_email',
	static function ( $mail ) {
		$GLOBALS['estrategia_web_forms_test_text'] = $mail['text'];
		return $mail;
	},
	999
);

// ONE OPTION PER EMAIL (estrategia_web_forms_test_mails_<unique>), read in the
// order they were inserted. Until 28-10-2026 they were one list in one option,
// read, appended to and saved again: two submissions at once (the tests now
// send independent ones two at a time) lost one of the emails, SILENTLY
// («no acknowledgement to the sender» one run in three). An insert per email
// cannot lose another one.
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$to   = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
		$fail = (bool) array_filter( $to, static fn( $t ) => str_contains( (string) $t, 'fail@' ) );
		add_option(
			ESTRATEGIA_WEB_FORMS_TEST_MAILS . '_' . str_replace( '.', '', uniqid( '', true ) ),
			array(
				'to'      => array_values( $to ),
				'subject' => $atts['subject'],
				'html'    => $atts['message'],
				'text'    => $GLOBALS['estrategia_web_forms_test_text'],
				'headers' => $atts['headers'],
				'sent'    => ! $fail,
			),
			'',
			false
		);
		return ! $fail;
	},
	10,
	2
);

// A JS submission sent with the header «X-EW-Forms-Test-State: 1» (submit(…, { state: true }) in
// tests/lib.mjs) answers, besides its own JSON, «_test_state»: the intercepted emails and the log as
// they are when it ends. One request less per submission a test checks (28-10-2026, round 2); what
// the form's JavaScript gets is still the same JSON, the key is only added for that header.
add_filter(
	'rest_post_dispatch',
	static function ( $response, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || '/estrategia-web-forms/v1/submit' !== $request->get_route() || '1' !== (string) $request->get_header( 'x_ew_forms_test_state' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$data['_test_state'] = array(
				'mails'       => estrategia_web_forms_test_mails(),
				'submissions' => \EstrategiaWeb\Forms\Submissions::query( '', 0 )['rows'],
			);
			$response->set_data( $data );
		}
		return $response;
	},
	10,
	3
);

/** The intercepted emails, in the order they were sent. */
function estrategia_web_forms_test_mails(): array {
	global $wpdb;
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id", $wpdb->esc_like( ESTRATEGIA_WEB_FORMS_TEST_MAILS . '_' ) . '%' ) ); // phpcs:ignore
	return array_map( 'maybe_unserialize', $rows );
}

add_action(
	'rest_api_init',
	static function () {
		$ns    = 'estrategia-web-forms-test/v1';
		$open  = '__return_true';
		$route = static function ( string $path, string $methods, callable $cb ) use ( $ns, $open ) {
			register_rest_route( $ns, $path, array( 'methods' => $methods, 'callback' => $cb, 'permission_callback' => $open ) );
		};

		/*
		 * The reset AND the seed of a test file, in ONE request (28-10-2026: a
		 * request to the test WordPress costs ~1,3 s whatever it does, and the
		 * battery's time is the number of requests). Body, all optional:
		 *   core        (true)  the core reset first (tests/wp/estrategia-web-site-test.php), in this same request
		 *   marker      (true)  the «forms» marker: the site in en_US (the «locale» filter above). false for WPML, where WPML says the language
		 *   locale              a forced locale (estrategia_web_forms_test_locale); the reset always removes the previous one
		 *   unregister          String Translation strings (forms context) removed before the settings are saved
		 *   settings            merged over the test defaults (min_seconds 1, no rate limit): a second save,
		 *                       which is what registers their texts in WPML
		 *   purge               slugs to delete in any language (the WPML test route /purge), before seeding
		 *   forms               [{key, title, content, status}]: form posts (eweb_form). Every form post is deleted first, always
		 *   pages               [{key, slug, title, content, type: page|wp_block, status, translation: {slug, title, content, lang}}],
		 *                       created in order; «{{id:<key>}}» in a content is the id of an earlier form or page with that key.
		 *                       A page is rewritten by its slug (not duplicated) and loses its migration backups.
		 *                       With «translation», its WPML translation (the WPML test route /translate)
		 *   submissions         rows for the log, as Submissions::insert() takes them (with notifications)
		 * Returns { pages: {key: {id, url, content, translation}}, forms: {key: id}, submissions: [id] }.
		 * How to maintain: a test that needs a new kind of seed adds a key here, never N requests in its before().
		 * The trap: the modules list, rewrite rules and the like that the core reset deletes are read again
		 * in the NEXT request; what is seeded here (posts, options, rows) is there already.
		 */
		$route(
			'/reset',
			'POST',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				$p   = (array) $r->get_json_params();
				$sub = static function ( string $method, string $path, array $body = array() ) {
					$req = new WP_REST_Request( $method, $path );
					if ( $body ) {
						$req->set_header( 'Content-Type', 'application/json' );
						$req->set_body( wp_json_encode( $body ) );
					}
					$res = rest_do_request( $req );
					if ( $res->is_error() ) {
						throw new RuntimeException( $path . ': ' . wp_json_encode( $res->get_data() ) );
					}
					return $res->get_data();
				};
				try {
					if ( $p['core'] ?? true ) {
						$sub( 'POST', '/estrategia-web-site-test/v1/reset' );
					}
					wp_set_current_user( 1 ); // As the administrator (WPML gives a saved page the current language through him).
					$wpdb->query( 'DELETE FROM ' . \EstrategiaWeb\Forms\Migrations::submissions_table() ); // phpcs:ignore
					$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%estrategia_web_forms_%'" ); // phpcs:ignore
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s", ESTRATEGIA_WEB_FORMS_TEST_MAILS, $wpdb->esc_like( ESTRATEGIA_WEB_FORMS_TEST_MAILS . '_' ) . '%' ) ); // phpcs:ignore
					// {unregister: [names]}: those String Translation strings of the forms context are removed first,
					// so that saving the settings below registers them anew (and a test sees how, not a leftover).
					foreach ( (array) ( $p['unregister'] ?? array() ) as $name ) {
						if ( function_exists( 'icl_unregister_string' ) ) {
							icl_unregister_string( \EstrategiaWeb\Forms\Language::STRING_CONTEXT, (string) $name );
						}
					}
					$defaults = array_merge( \EstrategiaWeb\Forms\Settings::DEFAULTS, array( 'min_seconds' => 1, 'rate_limit' => 0 ) );
					update_option( 'estrategia_web_forms_settings', $defaults );
					if ( ! empty( $p['settings'] ) ) {
						// A second save, as /settings does: a CHANGE of the option is what registers its texts in WPML (module.php).
						update_option( 'estrategia_web_forms_settings', array_merge( $defaults, (array) $p['settings'] ) );
					}
					delete_option( 'estrategia_web_forms_test_locale' );
					if ( ! empty( $p['locale'] ) ) {
						update_option( 'estrategia_web_forms_test_locale', sanitize_text_field( (string) $p['locale'] ) );
					}
					if ( $p['marker'] ?? true ) {
						update_option( 'estrategia_web_test_suite', 'forms' ); // See the «locale» filter above.
					} else {
						delete_option( 'estrategia_web_test_suite' );
					}
					if ( ! empty( $p['purge'] ) ) {
						$sub( 'POST', '/estrategia-web-wpml-test/v1/purge', array( 'slugs' => array_values( (array) $p['purge'] ) ) );
					}
					foreach ( get_posts( array( 'post_type' => 'eweb_form', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) as $old ) {
						wp_delete_post( $old, true );
					}

					kses_remove_filters();
					$ids  = array();
					$fill = static function ( string $content ) use ( &$ids ): string {
						return preg_replace_callback( '/\{\{id:([a-z0-9_-]+)\}\}/', static fn( $m ) => (string) ( $ids[ $m[1] ] ?? 0 ), $content );
					};
					$out = array( 'pages' => array(), 'forms' => array(), 'submissions' => array() );
					foreach ( (array) ( $p['forms'] ?? array() ) as $i => $f ) {
						$id = wp_insert_post( wp_slash( array( 'post_type' => 'eweb_form', 'post_status' => (string) ( $f['status'] ?? 'publish' ), 'post_title' => (string) ( $f['title'] ?? 'Form' ), 'post_content' => $fill( (string) $f['content'] ) ) ), true );
						if ( is_wp_error( $id ) ) {
							throw new RuntimeException( $id->get_error_message() );
						}
						$key                   = (string) ( $f['key'] ?? $i );
						$ids[ $key ]           = (int) $id;
						$out['forms'][ $key ] = (int) $id;
					}
					foreach ( (array) ( $p['pages'] ?? array() ) as $i => $pg ) {
						$type = 'wp_block' === ( $pg['type'] ?? '' ) ? 'wp_block' : 'page';
						$slug = sanitize_title( $pg['slug'] ?? 'form' );
						$old  = get_page_by_path( $slug, OBJECT, $type );
						$id   = wp_insert_post(
							wp_slash(
								array(
									'ID'           => $old ? $old->ID : 0,
									'post_type'    => $type,
									'post_status'  => (string) ( $pg['status'] ?? 'publish' ),
									'post_title'   => (string) ( $pg['title'] ?? 'Form' ),
									'post_name'    => $slug,
									'post_content' => $fill( (string) $pg['content'] ),
								)
							),
							true
						);
						if ( is_wp_error( $id ) ) {
							throw new RuntimeException( $id->get_error_message() );
						}
						delete_post_meta( $id, \EstrategiaWeb\Forms\Form_Migration::BACKUP_META );
						$key         = (string) ( $pg['key'] ?? $slug );
						$ids[ $key ] = (int) $id;
						$one         = array(
							'id'      => (int) $id,
							'url'     => get_permalink( $id ),
							'content' => (string) get_post_field( 'post_content', $id, 'raw' ),
						);
						if ( ! empty( $pg['translation'] ) ) {
							$t                  = (array) $pg['translation'];
							$one['translation'] = $sub( 'POST', '/estrategia-web-wpml-test/v1/translate', array( 'kind' => 'post', 'type' => $type, 'original' => (int) $id, 'lang' => $t['lang'] ?? 'en', 'title' => $t['title'] ?? '', 'slug' => $t['slug'] ?? '', 'content' => $fill( (string) ( $t['content'] ?? '' ) ) ) );
						}
						$out['pages'][ $key ] = $one;
					}
					foreach ( (array) ( $p['submissions'] ?? array() ) as $row ) {
						$out['submissions'][] = \EstrategiaWeb\Forms\Submissions::insert( array_merge( array( 'form_title' => '', 'definition_hash' => '', 'post_id' => 0, 'page_url' => '', 'lang' => 'en_US', 'status' => 'sent', 'data' => array(), 'notifications' => array(), 'ip' => '127.0.0.0', 'user_agent' => 'estrategia-web-forms-test/1.0' ), (array) $row ) );
					}
					return $out;
				} catch ( Throwable $e ) {
					return new WP_Error( 'forms_reset', $e->getMessage(), array( 'status' => 500 ) );
				}
			}
		);

		// What a submission left behind, in one request: the intercepted emails and the log.
		$route(
			'/state',
			'GET',
			static fn() => array(
				'mails'       => estrategia_web_forms_test_mails(),
				'submissions' => \EstrategiaWeb\Forms\Submissions::query( '', 0 )['rows'],
			)
		);

		// ?ids=1,2,3: each post's saved content and its migration backups, in one request.
		$route(
			'/posts',
			'GET',
			static function ( WP_REST_Request $r ) {
				$out = array();
				foreach ( array_filter( array_map( 'intval', explode( ',', (string) $r->get_param( 'ids' ) ) ) ) as $id ) {
					$out[ $id ] = array(
						'content' => (string) get_post_field( 'post_content', $id, 'raw' ),
						'backups' => get_post_meta( $id, \EstrategiaWeb\Forms\Form_Migration::BACKUP_META ),
					);
				}
				return $out;
			}
		);

		// [{...}, …]: several calls to the WPML test route /string in one request, in order (tests/wp/estrategia-web-wpml-test.php).
		$route(
			'/strings',
			'POST',
			static function ( WP_REST_Request $r ) {
				$out = array();
				foreach ( (array) $r->get_json_params() as $body ) {
					$req = new WP_REST_Request( 'POST', '/estrategia-web-wpml-test/v1/string' );
					$req->set_header( 'Content-Type', 'application/json' );
					$req->set_body( wp_json_encode( $body ) );
					$res = rest_do_request( $req );
					if ( $res->is_error() ) {
						return new WP_Error( 'forms_strings', wp_json_encode( $res->get_data() ), array( 'status' => 500 ) );
					}
					$out[] = $res->get_data();
				}
				return $out;
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
				$slug = sanitize_title( $p['slug'] ?? 'form' );
				// {id}: that post's content is rewritten as it is (its type, slug and WPML language stay).
				$old = ! empty( $p['id'] ) ? get_post( (int) $p['id'] ) : get_page_by_path( $slug, OBJECT, $type );
				if ( ! empty( $p['id'] ) && $old ) {
					$type = $old->post_type;
					$slug = $old->post_name;
				}
				$id = wp_insert_post(
					wp_slash(
						array(
							'ID'           => $old ? $old->ID : 0,
							'post_type'    => $type,
							'post_status'  => 'publish',
							'post_title'   => $p['title'] ?? 'Form',
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

		// 2.0.0, the form posts and their migrator (tests/form-ref.test.mjs, form-migration*.test.mjs).
		// {dry, ids}: Form_Migration::run(), as the WP-CLI command, as the administrator.
		// {read: true}: and, in the same request, what it left: «after» = {forms: GET /eweb-forms, posts: GET /posts?ids=<ids>}.
		$route(
			'/migrate-forms',
			'POST',
			static function ( WP_REST_Request $r ) {
				wp_set_current_user( 1 );
				$ids = array_map( 'intval', (array) $r->get_param( 'ids' ) );
				$out = \EstrategiaWeb\Forms\Form_Migration::run( (bool) $r->get_param( 'dry' ), $ids );
				// {point: {post, lang}}: then that post's content becomes a reference to the form post in that language
				// (form-migration-wpml.test.mjs: a translated page pointing to the ORIGINAL's form post). Read before
				// it, «after» still shows what the migration left.
				$point = (array) $r->get_param( 'point' );
				$read  = null;
				if ( $r->get_param( 'read' ) ) {
					$forms = rest_do_request( new WP_REST_Request( 'GET', '/estrategia-web-forms-test/v1/eweb-forms' ) );
					$posts = new WP_REST_Request( 'GET', '/estrategia-web-forms-test/v1/posts' );
					$posts->set_param( 'ids', implode( ',', $ids ) );
					$read = array(
						'forms' => $forms->get_data(),
						'posts' => rest_do_request( $posts )->get_data(),
					);
				}
				if ( $point ) {
					foreach ( get_posts( array( 'post_type' => 'eweb_form', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) as $form_id ) {
						$lang = apply_filters( 'wpml_post_language_details', null, $form_id );
						if ( is_array( $lang ) && ( $lang['language_code'] ?? '' ) === $point['lang'] ) {
							kses_remove_filters();
							wp_update_post( wp_slash( array( 'ID' => (int) $point['post'], 'post_content' => '<!-- wp:estrategia-web-forms/form-ref {"ref":' . (int) $form_id . '} /-->' ) ) );
							$out['pointed'] = (int) $form_id;
						}
					}
				}
				if ( $read ) {
					$out['after'] = $read;
				}
				return $out;
			}
		);
		// 2.0.1 (tests/marker-migration.test.mjs). {dry, ids}: Marker_Migration::run(), as the WP-CLI command, and in
		// the same request what it left in those posts: «after» = {id: {content, backups}}, plus the snapshot backup option.
		$route(
			'/migrate-form-markers',
			'POST',
			static function ( WP_REST_Request $r ) {
				wp_set_current_user( 1 );
				if ( $r->get_param( 'fresh' ) ) {
					delete_option( \EstrategiaWeb\Forms\Marker_Migration::BACKUP_OPTION );
				}
				$out   = \EstrategiaWeb\Forms\Marker_Migration::run( (bool) $r->get_param( 'dry' ) );
				$after = array();
				foreach ( array_map( 'intval', (array) $r->get_param( 'ids' ) ) as $id ) {
					$after[ $id ] = array(
						'content' => (string) get_post_field( 'post_content', $id, 'raw' ),
						'backups' => get_post_meta( $id, \EstrategiaWeb\Forms\Marker_Migration::BACKUP_META ),
					);
				}
				$out['after']           = $after;
				$out['snapshot_backup'] = array_keys( (array) get_option( \EstrategiaWeb\Forms\Marker_Migration::BACKUP_OPTION, array() ) );
				return $out;
			}
		);
		// GET: every form post (any status but trash) with its content, language and WPML trid. DELETE: all of them, for good.
		$route(
			'/eweb-forms',
			'GET,DELETE',
			static function ( WP_REST_Request $r ) {
				$ids = get_posts( array( 'post_type' => 'eweb_form', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'orderby' => 'ID', 'order' => 'ASC' ) );
				$out = array();
				foreach ( $ids as $id ) {
					if ( 'DELETE' === $r->get_method() ) {
						wp_delete_post( $id, true );
						continue;
					}
					$lang  = apply_filters( 'wpml_post_language_details', null, $id );
					$out[] = array(
						'id'      => (int) $id,
						'title'   => get_the_title( $id ),
						'status'  => get_post_status( $id ),
						'content' => (string) get_post_field( 'post_content', $id, 'raw' ),
						'lang'    => is_array( $lang ) ? (string) ( $lang['language_code'] ?? '' ) : '',
						'trid'    => (int) apply_filters( 'wpml_element_trid', null, $id, 'post_eweb_form' ),
					);
				}
				return $out;
			}
		);
		$route( '/backup/(?P<id>\d+)', 'GET', static fn( WP_REST_Request $r ) => get_post_meta( (int) $r['id'], \EstrategiaWeb\Forms\Form_Migration::BACKUP_META ) );
		// {title, content, status}: a form post, as the administrator would save it.
		$route(
			'/eweb-form',
			'POST',
			static function ( WP_REST_Request $r ) {
				kses_remove_filters();
				$id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => 'eweb_form',
							'post_status'  => (string) ( $r->get_param( 'status' ) ?: 'publish' ),
							'post_title'   => (string) $r->get_param( 'title' ),
							'post_content' => (string) $r->get_param( 'content' ),
						)
					),
					true
				);
				return is_wp_error( $id ) ? $id : array( 'id' => (int) $id );
			}
		);

		$route( '/mails', 'GET', static fn() => estrategia_web_forms_test_mails() );

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
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				// {backdate: {id, days}}: that submission is made older first, in the same request (what /backdate does).
				$back = (array) $r->get_param( 'backdate' );
				if ( $back ) {
					$wpdb->update( \EstrategiaWeb\Forms\Migrations::submissions_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - (int) $back['days'] * DAY_IN_SECONDS ) ), array( 'id' => (int) $back['id'] ) );
				}
				do_action( \EstrategiaWeb\Forms\Cron::HOOK );
				return array(
					'scheduled'  => (bool) wp_next_scheduled( \EstrategiaWeb\Forms\Cron::HOOK ),
					'last_purge' => get_option( 'estrategia_web_forms_last_purge' ),
					'left'       => array_map( static fn( $row ) => (int) $row['id'], \EstrategiaWeb\Forms\Submissions::query( '', 0 )['rows'] ),
				);
			}
		);
	}
);
