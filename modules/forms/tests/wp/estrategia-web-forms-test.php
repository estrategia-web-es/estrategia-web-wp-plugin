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
 *   is stored in the estrategia_web_forms_test_mails option for the test to
 *   read. A recipient containing «fail@» simulates a sending failure.
 * - Routes estrategia-web-forms-test/v1/*: reset, settings, page (also a synced
 *   block with type=wp_block), post/<id> (the saved post_content),
 *   mails, submissions, locale, backdate, purge.
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
				update_option( 'estrategia_web_test_suite', 'forms' ); // See the «locale» filter above.
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
				$slug = sanitize_title( $p['slug'] ?? 'form' );
				$old  = get_page_by_path( $slug, OBJECT, $type );
				$id   = wp_insert_post(
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

		// 1.9.0, the form posts and their migrator (tests/form-ref.test.mjs, form-migration*.test.mjs).
		// {dry, ids}: Form_Migration::run(), as the WP-CLI command, as the administrator.
		$route(
			'/migrate-forms',
			'POST',
			static function ( WP_REST_Request $r ) {
				wp_set_current_user( 1 );
				return \EstrategiaWeb\Forms\Form_Migration::run( (bool) $r->get_param( 'dry' ), array_map( 'intval', (array) $r->get_param( 'ids' ) ) );
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
