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
 * limit → validation → log → notifications.
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
			return self::respond( $is_js, $page_url, '', 400, 'unknown_form', __( 'This form is no longer available. Please reload the page.', 'estrategia-web' ) );
		}

		$lang  = (string) $definition['lang'];
		$state = Language::switch_to( $lang );
		try {
			return self::handle( $params, $definition, $is_js, $page_url );
		} finally {
			Language::restore( $state );
		}
	}

	private static function handle( array $params, array $definition, bool $is_js, string $page_url ): \WP_REST_Response {
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
			Definitions::hash( $definition ),
			time(),
			wp_salt( 'nonce' ),
			(int) Settings::get( 'min_seconds' ),
			Settings::TOKEN_MAX_AGE
		);
		if ( Token::OK !== $check ) {
			do_action( 'estrategia_web_forms_rejected', 'token_' . $check, $definition );
			$message = Token::TOO_FAST === $check
				? __( 'The form was sent too quickly. Please wait a few seconds and try again.', 'estrategia-web' )
				: __( 'The form has expired. Please reload the page and try again.', 'estrategia-web' );
			return self::respond( $is_js, $page_url, $form_id, 403, 'token_' . $check, $message );
		}

		// 3. Per-IP submission limit in a fixed window.
		$ip = self::client_ip();
		if ( ! self::within_rate_limit( $ip ) ) {
			do_action( 'estrategia_web_forms_rejected', 'rate_limit', $definition );
			return self::respond( $is_js, $page_url, $form_id, 429, 'rate_limit', __( 'Too many messages from your connection. Please try again later.', 'estrategia-web' ) );
		}

		// 4. Server-side validation, field by field, according to the definition.
		$input  = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
		$result = Validator::validate( $definition, $input );
		if ( $result['errors'] ) {
			return self::respond( $is_js, $page_url, $form_id, 422, 'invalid', $failure, $result['errors'], $result['values'] );
		}

		// 5. Log first: if the notifications fail, the submission is not lost.
		$data = array();
		foreach ( (array) $definition['fields'] as $field ) {
			$data[] = array(
				'name'  => $field['name'],
				'label' => $field['label'],
				'type'  => $field['type'],
				'value' => $result['values'][ $field['name'] ] ?? '',
			);
		}
		$id = Submissions::insert(
			array(
				'form_id'         => $form_id,
				'form_title'      => $definition['title'],
				'definition_hash' => Definitions::hash( $definition ),
				'post_id'         => (int) $definition['post_id'],
				'page_url'        => $page_url,
				'lang'            => $lang,
				'status'          => 'pending',
				'data'            => $data,
				'notifications'   => array(),
				'ip'              => Ip::truncate( $ip ),
				'user_agent'      => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			)
		);

		// 6. Notifications.
		$notifications = Notifier::send_all( $definition, $result['values'], array( 'page_url' => $page_url, 'lang' => $lang ) );
		$failed        = count( array_filter( $notifications, static fn( $n ) => 'sent' !== $n['status'] ) );
		$status        = 0 === $failed ? 'sent' : ( $failed === count( $notifications ) ? 'failed' : 'partial' );
		Submissions::update_notifications( $id, $status, $notifications );

		do_action( 'estrategia_web_forms_submitted', $id, $definition, $result['values'] );

		return self::respond( $is_js, $page_url, $form_id, 200, 'ok', $success );
	}

	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * The visitor's IP. Only a site behind a TRUSTED proxy changes it
		 * (e.g. by reading X-Forwarded-For from its CDN).
		 */
		return (string) apply_filters( 'estrategia_web_forms_client_ip', $ip );
	}

	private static function within_rate_limit( string $ip ): bool {
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
	 */
	private static function respond( bool $is_js, string $page_url, string $form_id, int $http, string $code, string $message, array $errors = array(), array $values = array() ): \WP_REST_Response {
		if ( $is_js ) {
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
		$target   = wp_validate_redirect( $page_url, home_url( '/' ) );
		$location = add_query_arg( Blocks::RESULT_PARAM, $key, $target ) . ( '' !== $form_id ? '#af-' . $form_id . '-status' : '' );

		$response = new \WP_REST_Response( null, 303 );
		$response->header( 'Location', $location );
		return $response;
	}
}
