<?php
/**
 * The submission endpoint: POST /wp-json/estrategia-web-forms/v1/submit
 *
 * It is the SAME target with JavaScript and without it:
 * - With JS (view.js), the request carries _af_js=1 and the response is JSON.
 * - Without JS, the browser posts the form as is (the <form> action) and the
 *   response is a 303 redirect to the page, with ?af_result=<key>. The key
 *   points to a 10-minute transient with the message, the errors and what
 *   was typed, and the form is rendered again with that.
 *
 * The order of the checks matters and is this one, from cheapest to most
 * expensive: definition → honeypot → token (nonce + minimum time) → per-IP
 * limit → validation → entry context (never rejects) → log → notifications.
 *
 * permission_callback is __return_true ON PURPOSE and is declared (row 17):
 * a contact form is public by definition. What protects it is not who calls
 * but what they can do: only submit to a definition the server rendered,
 * with its token, within the limit.
 *
 * How to maintain:
 * - Edge case: a filled-in honeypot ANSWERS SUCCESS and stores nothing. This
 *   is on purpose: telling the bot it failed teaches it not to fill it in.
 * - The trap: with a form on a cached page, the token is the cached one.
 *   That is why it expires after 7 days (Settings::TOKEN_MAX_AGE) and why
 *   view.js asks for a new one on first focus. Without JS and with a cache
 *   older than 7 days, the submission fails with «the form has expired».
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const NS = 'estrategia-web-forms/v1';

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
		// 2.11.0, dossier mode: «send me my link» and a dossier's file for its owner.
		// Public like /submit; what protects them is in Dossier::send_link() and Dossier::file().
		register_rest_route(
			self::NS,
			'/dossier-link',
			array(
				'methods'             => 'POST',
				'callback'            => array( Dossier::class, 'send_link' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( Dossier::class, 'file' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'def' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public static function token( \WP_REST_Request $request ): \WP_REST_Response {
		$hash = (string) $request->get_param( 'def' );
		if ( ! Definitions::load( $hash ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 404 );
		}
		$response = new \WP_REST_Response( array( 'token' => Token::create( $hash, time(), wp_salt( 'nonce' ) ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function submit( \WP_REST_Request $request ): \WP_REST_Response {
		$params     = (array) $request->get_body_params();
		$is_js      = ! empty( $params['_af_js'] );
		$definition = Definitions::load( (string) ( $params['_af_def'] ?? '' ) );
		$page_url   = esc_url_raw( (string) ( $params['_af_page'] ?? '' ) );

		if ( ! $definition ) {
			return self::respond( $is_js, $page_url, '', 400, 'unknown_form', __( 'This form is no longer available. Please reload the page.', 'estrategiaweb-toolkit' ) );
		}

		$lang  = (string) $definition['lang'];
		$state = Language::switch_to( $lang );
		try {
			return self::handle( $request, $params, $definition, (string) $params['_af_def'], $is_js, $page_url );
		} finally {
			Language::restore( $state );
		}
	}

	/**
	 * @param string $hash The key the definition was stored and loaded under. Used as
	 *                     it is, NOT recomputed from $definition: since 2.0.1 a stored
	 *                     snapshot can be rewritten in place (Marker_Migration), and a
	 *                     page cached before that still carries a token signed for
	 *                     the key (seen red in tests/marker-migration.test.mjs).
	 */
	private static function handle( \WP_REST_Request $request, array $params, array $definition, string $hash, bool $is_js, string $page_url ): \WP_REST_Response {
		$form_id = (string) $definition['form_id'];
		$lang    = (string) $definition['lang'];
		$success = '' !== $definition['success_message'] ? $definition['success_message'] : Settings::text( 'success_message', $lang );
		$failure = '' !== $definition['error_message'] ? $definition['error_message'] : Settings::text( 'error_message', $lang );

		// 1. Honeypot: fake success, nothing stored.
		if ( '' !== trim( (string) ( $params['af_website'] ?? '' ) ) ) {
			do_action( 'estrategia_web_forms_rejected', 'honeypot', $definition );
			return self::respond( $is_js, $page_url, $form_id, 200, 'ok', $success );
		}

		// 2. Token: signed by this server, for this definition, neither too early nor expired.
		$check = Token::verify(
			(string) ( $params['_af_token'] ?? '' ),
			$hash,
			time(),
			wp_salt( 'nonce' ),
			(int) Settings::get( 'min_seconds' ),
			Settings::TOKEN_MAX_AGE
		);
		if ( Token::OK !== $check ) {
			do_action( 'estrategia_web_forms_rejected', 'token_' . $check, $definition );
			$message = Token::TOO_FAST === $check
				? __( 'The form was sent too quickly. Please wait a few seconds and try again.', 'estrategiaweb-toolkit' )
				: __( 'The form has expired. Please reload the page and try again.', 'estrategiaweb-toolkit' );
			return self::respond( $is_js, $page_url, $form_id, 403, 'token_' . $check, $message );
		}

		// 3. Per-IP submission limit in a fixed window.
		$ip = self::client_ip();
		if ( ! self::within_rate_limit( $ip ) ) {
			do_action( 'estrategia_web_forms_rejected', 'rate_limit', $definition );
			return self::respond( $is_js, $page_url, $form_id, 429, 'rate_limit', __( 'Too many messages from your connection. Please try again later.', 'estrategiaweb-toolkit' ) );
		}

		// 4. Dossier (2.11.0): the link the visitor came with, if any. A link that does not open
		// a dossier of THIS form is refused before anything is read or written.
		$is_dossier = ! empty( $definition['dossier'] );
		$token      = $is_dossier ? (string) ( $params['_af_dossier'] ?? '' ) : '';
		$row        = null;
		if ( '' !== $token ) {
			$row = Dossier::load( $token, $form_id );
			if ( ! $row ) {
				do_action( 'estrategia_web_forms_rejected', 'dossier_invalid', $definition );
				return self::respond( $is_js, $page_url, $form_id, 403, 'dossier_invalid', __( 'This link has expired or is no longer valid. You can ask for a new one below the form.', 'estrategiaweb-toolkit' ) );
			}
		}

		// 5. Server-side validation, field by field, according to the definition; files apart.
		$input    = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
		$result   = Validator::validate( $definition, $input );
		$before   = array();
		$multiple = array();
		$remove   = $row ? array_map( 'strval', (array) ( $params['af_remove'] ?? array() ) ) : array();
		foreach ( $row ? (array) $row['data'] : array() as $item ) {
			$before[ (string) $item['name'] ] = (array) ( $item['files'] ?? array() );
		}
		foreach ( (array) $definition['fields'] as $field ) {
			$multiple[ (string) $field['name'] ] = ! empty( $field['multiple'] );
		}
		$uploads = Files::normalize( (array) ( $request->get_file_params()['files'] ?? array() ) );
		$checked = Files::validate( $definition, $uploads, Files::merge( $before, $remove, array(), $multiple )['files'] );
		$errors  = $result['errors'] + $checked['errors'];
		if ( $errors ) {
			return self::respond( $is_js, $page_url, $form_id, 422, 'invalid', $failure, $errors, $result['values'] );
		}
		$stored = Files::store( $checked['accepted'] );
		if ( null === $stored ) {
			return self::respond( $is_js, $page_url, $form_id, 500, 'files_failed', __( 'The files could not be saved. Please try again later.', 'estrategiaweb-toolkit' ), array(), $result['values'] );
		}

		// 6. Log first: if the notifications fail, the submission is not lost.
		// The entry it was sent from, if its signed id holds (no rejection when it does not: Token::context()).
		$post    = self::context_post( (string) ( $params['_af_ctx'] ?? '' ), $hash );
		$merged  = Files::merge( $before, $remove, $stored, $multiple );
		$dropped = $merged['dropped'];
		$values  = $result['values'];
		$data    = array();
		foreach ( (array) $definition['fields'] as $field ) {
			$item = array(
				'name'  => $field['name'],
				'label' => $field['label'],
				'type'  => $field['type'],
				'value' => $values[ $field['name'] ] ?? '',
			);
			if ( 'file' === $field['type'] ) {
				$item['files']            = $merged['files'][ $field['name'] ] ?? array();
				$item['value']            = implode( ', ', array_column( $item['files'], 'name' ) );
				$values[ $field['name'] ] = $item['value'];
			}
			$data[] = $item;
		}

		$event   = 'created';
		$extra   = array();
		$message = $success;
		$target  = '';
		if ( ! $is_dossier ) {
			$id = Submissions::insert( self::base_row( $definition, $hash, $page_url, $ip, $data, $post ) );
		} else {
			$missing = Dossier::missing( $definition, $data );
			$owner   = strtolower( (string) ( $values[ Dossier::owner_field( $definition ) ] ?? '' ) );
			$now     = current_time( 'mysql', true );
			$expires = Dossier::expires( Dossier::days( $definition ) );
			if ( $row ) {
				$event = 'updated';
				$id    = (int) $row['id'];
				Submissions::update_dossier(
					$id,
					array(
						'data'           => $data,
						'updated_at'     => $now,
						'access_expires' => $expires, // Using the link keeps it alive (README, «Dossier mode»).
						'owner_email'    => $owner,
						'complete'       => ! $missing,
						'missing'        => $missing,
						'history'        => Dossier::history_add( (array) $row['history'], Dossier::actor(), 'updated', array( 'fields' => Dossier::changed( (array) $row['data'], $data ) ) ),
					)
				);
				Dossier::forget();
				Files::delete( $dropped );
			} else {
				$token = Dossier::new_token();
				$id    = Submissions::insert(
					self::base_row( $definition, $hash, $page_url, $ip, $data, $post ) + array(
						'access_hash'    => Dossier::hash( $token ),
						'access_expires' => $expires,
						'owner_email'    => $owner,
						'complete'       => ! $missing,
						'missing'        => $missing,
						'history'        => Dossier::history_add( array(), 'client', 'created' ),
					)
				);
			}
			$labels = Dossier::labels( $definition, $missing );
			$target = Dossier::url( Dossier::page( (int) $definition['post_id'], $page_url ), $token );
			$extra  = array(
				Markers::DOSSIER_URL => $target,
				Markers::MISSING     => implode( ', ', $labels ),
			);
			if ( $missing ) {
				// Short: the page it lands on lists what is missing right above the fields (Blocks::dossier_notice()).
				$message = __( 'Saved. You can come back whenever you want with the link we have sent to your email.', 'estrategiaweb-toolkit' );
			}
		}

		// 7. Notifications: the ones for this event («created» or, in a dossier, «updated»).
		$definition['notifications'] = Definitions::for_event( (array) $definition['notifications'], $event );
		$notifications = Notifier::send_all( $definition, $values, array( 'page_url' => $page_url, 'lang' => $lang, 'extra' => $extra, 'post' => $post ) );
		$failed        = count( array_filter( $notifications, static fn( $n ) => 'sent' !== $n['status'] ) );
		$status        = 0 === $failed ? 'sent' : ( $failed === count( $notifications ) ? 'failed' : 'partial' );
		Submissions::update_notifications( $id, $status, $notifications );

		do_action( 'estrategia_web_forms_submitted', $id, $definition, $values );

		return self::respond( $is_js, $page_url, $form_id, 200, 'ok', $message, array(), array(), $target );
	}

	/** The columns every submission has, dossier or not. */
	private static function base_row( array $definition, string $hash, string $page_url, string $ip, array $data, ?\WP_Post $post ): array {
		return array(
			'form_id'         => (string) $definition['form_id'],
			'form_title'      => $definition['title'],
			'definition_hash' => $hash,
			'post_id'         => (int) $definition['post_id'],
			'context_post_id' => $post ? (int) $post->ID : 0,
			'page_url'        => $page_url,
			'lang'            => (string) $definition['lang'],
			'status'          => 'pending',
			'data'            => $data,
			'notifications'   => array(),
			'ip'              => Ip::truncate( $ip ),
			'user_agent'      => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
		);
	}

	/**
	 * The entry of a submission's context (_af_ctx, Token::context()): the
	 * post whose id is signed for THIS definition, if it still exists and is
	 * still public (published, of a viewable type). Anything else is null and
	 * the entry markers come out empty: a draft, a trashed obituary, a forged
	 * or copied signature, no _af_ctx at all (a page cached before the field
	 * existed).
	 */
	public static function context_post( string $context, string $hash ): ?\WP_Post {
		$id   = Token::context_id( $context, $hash, wp_salt( 'nonce' ) );
		$post = $id ? get_post( $id ) : null;
		return $post instanceof \WP_Post && 'publish' === $post->post_status && is_post_publicly_viewable( $post ) ? $post : null;
	}

	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * The visitor's IP. Only a site behind a TRUSTED proxy changes it
		 * (e.g. by reading X-Forwarded-For from its CDN).
		 */
		return (string) apply_filters( 'estrategia_web_forms_client_ip', $ip );
	}

	public static function within_rate_limit( string $ip ): bool {
		$limit  = (int) Settings::get( 'rate_limit' );
		$window = max( 1, (int) Settings::get( 'rate_window' ) ) * MINUTE_IN_SECONDS;
		if ( $limit <= 0 ) {
			return true;
		}
		$key    = 'estrategia_web_forms_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 24 );
		$bucket = get_transient( $key );
		$now    = time();
		if ( ! is_array( $bucket ) || $bucket['until'] <= $now ) {
			$bucket = array(
				'count' => 0,
				'until' => $now + $window,
			);
		}
		if ( $bucket['count'] >= $limit ) {
			return false;
		}
		++$bucket['count'];
		set_transient( $key, $bucket, $bucket['until'] - $now );
		return true;
	}

	/**
	 * @param array<string, string> $errors
	 * @param array<string, string> $values
	 * @param string                $target 2.11.0: where to go afterwards instead of back to the page (a
	 *                                      dossier's own link). With JS too: the answer carries «redirect»
	 *                                      and view.js goes there, so both ways land on the same page.
	 */
	public static function respond( bool $is_js, string $page_url, string $form_id, int $http, string $code, string $message, array $errors = array(), array $values = array(), string $target = '' ): \WP_REST_Response {
		if ( $is_js && '' === $target ) {
			return new \WP_REST_Response(
				array(
					'ok'      => 'ok' === $code,
					'code'    => $code,
					'message' => $message,
					'errors'  => (object) $errors,
				),
				$http
			);
		}

		$key = strtolower( wp_generate_password( 20, false ) );
		set_transient(
			'estrategia_web_forms_result_' . $key,
			array(
				'form_id' => $form_id,
				'status'  => $code,
				'message' => $message,
				'errors'  => $errors,
				'values'  => 'ok' === $code ? array() : $values,
			),
			10 * MINUTE_IN_SECONDS
		);
		$target   = wp_validate_redirect( '' !== $target ? $target : $page_url, home_url( '/' ) );
		$location = add_query_arg( Blocks::RESULT_PARAM, $key, $target ) . ( '' !== $form_id ? '#af-' . $form_id . '-status' : '' );

		if ( $is_js ) {
			return new \WP_REST_Response(
				array(
					'ok'       => 'ok' === $code,
					'code'     => $code,
					'message'  => $message,
					'errors'   => (object) $errors,
					'redirect' => $location,
				),
				$http
			);
		}
		$response = new \WP_REST_Response( null, 303 );
		$response->header( 'Location', $location );
		return $response;
	}
}
