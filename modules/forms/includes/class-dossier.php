<?php
/**
 * Dossier mode (2.11.0): a submission its sender can come back to and
 * complete, with a private link, instead of a message sent once.
 *
 * Born for the grant applications of estrategiaweb.es (BDNS 897994, line
 * 3.1): the client has to hand over a dozen data and three documents, and
 * nobody has them all at once. A plain form forces them to start over; the
 * dossier keeps what they sent, says what is missing and lets them finish.
 * It is switched on PER FORM (the form block's «Dossier mode»): a form
 * without it behaves exactly as before 2.11.0.
 *
 * How it works:
 * - SAVING HALF-WAY. In a dossier form, «Required» means «needed for the
 *   dossier to be complete», not «needed to send». To save, only the fields
 *   marked «Required to save» are needed, plus the consent and the owner's
 *   email (owner_field(): the first email field marked «Required to save»,
 *   or the first email field). The row is stored as complete or not, with
 *   the list of what is missing.
 * - THE LINK. On the first save a random token (32 bytes) is created and
 *   only its sha256 is stored (access_hash). The link is the page with
 *   ?af_dossier=<token>; it goes out in the {{dossier_url}} marker of the
 *   notifications. Opening it renders the form with what was sent, the
 *   files with their download links, and saving updates the same row.
 * - IT EXPIRES after the form's «Link valid for» days (90 by default),
 *   counted from the last time it was issued or used: a client who keeps
 *   working on it does not lose it. The team can revoke it or send a new
 *   one, and the client can ask for a new one with their email («Send me
 *   my link»), which only ever goes to the address stored in the dossier
 *   and answers the same whether it exists or not.
 * - THE PAGE WITH THE LINK is not cached, not indexed and sends no
 *   referrer (boot()): the token must not end up in a cache, in a search
 *   engine or in another site's logs.
 *
 * How to maintain:
 * - Who and when: whoever touches the submission flow (class-rest.php)
 *   checks the dossier cases in tests/pure.test.mjs (the decisions: is_open,
 *   missing, owner_field…) and in tests/server.test.mjs (what only WordPress
 *   shows). The admin actions («Ask the client», «Revoke link») have no
 *   level 2 test: whoever changes them tries them by hand.
 * - Edge case: a dossier link opened on a page whose form is a DIFFERENT
 *   form (another form_id) loads nothing and says the link is not valid. The
 *   link belongs to its form, not to the page.
 * - Edge case: the team asking for something (Admin «Ask the client»)
 *   issues a NEW link and the old one stops working. That is on purpose:
 *   the email that asks carries the link, and there is only ever one.
 * - The trap: the token is in the URL. Anything that logs full URLs (an
 *   analytics tool with the query string, a server log shared with
 *   someone) sees it. The no-referrer and no-cache headers cover the other
 *   sites and the caches; the logs are each site's to look after.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Dossier {

	public const PARAM        = 'af_dossier';
	public const DEFAULT_DAYS = 90;
	public const MAX_DAYS     = 365;

	/** @var array<string, ?array> token => row, per request. */
	private static array $loaded = array();

	public static function boot(): void {
		add_action( 'send_headers', array( self::class, 'private_page_headers' ) );
		add_filter( 'wp_robots', array( self::class, 'noindex' ) );
	}

	/** No cache, no index, no referrer on a page opened with a dossier link. */
	public static function private_page_headers(): void {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Caching plugins (SG Optimizer, WP Rocket, W3TC) honour it.
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	public static function noindex( array $robots ): array {
		if ( isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/* ---------------------------------------------------------------- */
	/* Pure: tokens and what is missing (level 1, tests/php/pure.php)    */
	/* ---------------------------------------------------------------- */

	public static function new_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/** The shape of a token: 43 base64url characters. Anything else is not even looked up. PURE. */
	public static function is_token( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $token );
	}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * The field whose value is the owner's email: the first email field
	 * marked «Required to save», or else the first email field. PURE.
	 */
	public static function owner_field( array $definition ): string {
		$first = '';
		foreach ( (array) $definition['fields'] as $field ) {
			if ( 'email' !== $field['type'] ) {
				continue;
			}
			if ( ! empty( $field['required_to_save'] ) ) {
				return (string) $field['name'];
			}
			$first = '' !== $first ? $first : (string) $field['name'];
		}
		return $first;
	}

	/**
	 * The fields a save cannot go without. PURE.
	 *
	 * @return string[] Field names.
	 */
	public static function needed_to_save( array $definition ): array {
		$owner = self::owner_field( $definition );
		$out   = array();
		foreach ( (array) $definition['fields'] as $field ) {
			if ( ! empty( $field['required_to_save'] ) || ! empty( $field['consent'] ) || $field['name'] === $owner ) {
				$out[] = (string) $field['name'];
			}
		}
		return $out;
	}

	/**
	 * What is still missing for the dossier to be complete: the «Required»
	 * fields that are empty (a file field: without any file). PURE.
	 *
	 * @param array<int, array> $data The row's data (name, type, value, files).
	 * @return string[] Field names, in the form's order.
	 */
	public static function missing( array $definition, array $data ): array {
		$by_name = array();
		foreach ( $data as $item ) {
			$by_name[ (string) $item['name'] ] = $item;
		}
		$out = array();
		foreach ( (array) $definition['fields'] as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}
			$item  = $by_name[ $field['name'] ] ?? array();
			$empty = 'file' === $field['type'] ? empty( $item['files'] ) : '' === (string) ( $item['value'] ?? '' );
			if ( $empty ) {
				$out[] = (string) $field['name'];
			}
		}
		return $out;
	}

	/** Labels of a list of field names, for messages and emails. PURE. */
	public static function labels( array $definition, array $names ): array {
		$labels = array();
		foreach ( (array) $definition['fields'] as $field ) {
			if ( in_array( $field['name'], $names, true ) ) {
				$labels[] = '' !== (string) $field['label'] ? (string) $field['label'] : (string) $field['name'];
			}
		}
		return $labels;
	}

	/** The names of the fields whose value or files changed between two data lists. PURE. */
	public static function changed( array $before, array $after ): array {
		$old = array();
		foreach ( $before as $item ) {
			$old[ (string) $item['name'] ] = array( (string) ( $item['value'] ?? '' ), array_column( (array) ( $item['files'] ?? array() ), 'id' ) );
		}
		$out = array();
		foreach ( $after as $item ) {
			$now = array( (string) ( $item['value'] ?? '' ), array_column( (array) ( $item['files'] ?? array() ), 'id' ) );
			if ( ( $old[ (string) $item['name'] ] ?? array( '', array() ) ) !== $now ) {
				$out[] = (string) $item['name'];
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- */
	/* The link                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * The row a token opens, if it is valid, unexpired and (when given) of
	 * that form. Cached per request.
	 */
	public static function load( string $token, string $form_id = '' ): ?array {
		if ( ! self::is_token( $token ) ) {
			return null;
		}
		if ( ! array_key_exists( $token, self::$loaded ) ) {
			self::$loaded[ $token ] = Submissions::find_by_access_hash( self::hash( $token ) );
		}
		$row = self::$loaded[ $token ];
		return $row && self::is_open( $row, time(), $form_id ) ? $row : null;
	}

	/**
	 * Whether a dossier row may be opened now: it has a link (not revoked),
	 * it has not expired, and it is of that form ('' = any). PURE (level 1).
	 */
	public static function is_open( array $row, int $now, string $form_id = '' ): bool {
		$expires = (string) ( $row['access_expires'] ?? '' );
		if ( '' === (string) ( $row['access_hash'] ?? '' ) || '' === $expires || strtotime( $expires . ' UTC' ) < $now ) {
			return false;
		}
		return '' === $form_id || (string) $row['form_id'] === $form_id;
	}

	/** The token in the current URL, as it came ('' if none). */
	public static function token_in_url(): string {
		$raw = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token IS the credential.
		return $raw;
	}

	/** Forgets what load() cached (after the row changes in the same request). */
	public static function forget(): void {
		self::$loaded = array();
	}

	/**
	 * The page a dossier lives on, always on THIS site: the page the form was
	 * rendered on (post_id) or, without it, the page it was sent from, if it
	 * is ours. Never a URL the visitor chose: the link goes out in an email
	 * signed by the site (see the README, «Dossier mode»).
	 */
	public static function page( int $post_id, string $page_url ): string {
		$url = $post_id > 0 && 'publish' === get_post_status( $post_id ) ? (string) get_permalink( $post_id ) : '';
		if ( '' === $url ) {
			$url = wp_validate_redirect( remove_query_arg( array( self::PARAM, Blocks::RESULT_PARAM ), $page_url ), home_url( '/' ) );
		}
		return $url;
	}

	public static function url( string $page, string $token ): string {
		return add_query_arg( self::PARAM, $token, $page );
	}

	public static function days( array $definition ): int {
		$days = (int) ( $definition['dossier_days'] ?? self::DEFAULT_DAYS );
		return min( self::MAX_DAYS, max( 1, $days ) );
	}

	public static function expires( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
	}

	/**
	 * Issues a new link for a row: a new token, its hash stored, the old one
	 * dead. Returns the token (the only moment it exists in clear).
	 */
	public static function reissue( array $row, int $days, string $by, string $what, array $extra = array() ): string {
		$token = self::new_token();
		Submissions::update_dossier(
			(int) $row['id'],
			array(
				'access_hash'    => self::hash( $token ),
				'access_expires' => self::expires( $days ),
				'updated_at'     => current_time( 'mysql', true ),
				'history'        => self::history_add( (array) $row['history'], $by, $what, $extra ),
			)
		);
		self::forget();
		return $token;
	}

	public static function revoke( array $row, string $by ): void {
		Submissions::update_dossier(
			(int) $row['id'],
			array(
				'access_hash'    => '',
				'access_expires' => null,
				'history'        => self::history_add( (array) $row['history'], $by, 'revoked' ),
			)
		);
		self::forget();
	}

	/** @param array<string, mixed> $extra fields => [...], note => '...'. */
	public static function history_add( array $history, string $by, string $what, array $extra = array() ): array {
		$history[] = array_merge(
			array(
				'at'   => gmdate( 'c' ),
				'by'   => $by,
				'what' => $what,
			),
			$extra
		);
		return array_slice( $history, -200 );
	}

	/** Who is acting, for the history: «client» or the team member's login. */
	public static function actor(): string {
		$user = wp_get_current_user();
		return $user && $user->exists() ? 'team:' . $user->user_login : 'client';
	}

	/* ---------------------------------------------------------------- */
	/* «Send me my link» and «Ask the client»                            */
	/* ---------------------------------------------------------------- */

	/**
	 * POST /dossier-link: a new link to the stored email of the latest
	 * dossier of that form with that email. Answers the same whether it
	 * exists or not; same honeypot, token and per-IP limit as a submission.
	 */
	public static function send_link( \WP_REST_Request $request ): \WP_REST_Response {
		$params     = (array) $request->get_body_params();
		$is_js      = ! empty( $params['_af_js'] );
		$hash       = (string) ( $params['_af_def'] ?? '' );
		$definition = Definitions::load( $hash );
		$page_url   = esc_url_raw( (string) ( $params['_af_page'] ?? '' ) );
		if ( ! $definition || empty( $definition['dossier'] ) ) {
			return Rest::respond( $is_js, $page_url, '', 400, 'unknown_form', __( 'This form is no longer available. Please reload the page.', 'estrategiaweb-toolkit' ) );
		}
		$state = Language::switch_to( (string) $definition['lang'] );
		try {
			$form_id = (string) $definition['form_id'];
			$answer  = __( 'If there is a request with that email, we have just sent it a new link. Check your inbox (and the spam folder).', 'estrategiaweb-toolkit' );
			if ( '' !== trim( (string) ( $params['af_website'] ?? '' ) ) ) {
				return Rest::respond( $is_js, $page_url, $form_id, 200, 'link_sent', $answer );
			}
			$check = Token::verify( (string) ( $params['_af_token'] ?? '' ), $hash, time(), wp_salt( 'nonce' ), 0, Settings::TOKEN_MAX_AGE );
			if ( Token::OK !== $check ) {
				return Rest::respond( $is_js, $page_url, $form_id, 403, 'token_' . $check, __( 'The form has expired. Please reload the page and try again.', 'estrategiaweb-toolkit' ) );
			}
			if ( ! Rest::within_rate_limit( Rest::client_ip() ) ) {
				return Rest::respond( $is_js, $page_url, $form_id, 429, 'rate_limit', __( 'Too many messages from your connection. Please try again later.', 'estrategiaweb-toolkit' ) );
			}
			$email = sanitize_email( (string) ( $params['af_dossier_email'] ?? '' ) );
			$row   = '' !== $email ? Submissions::find_latest_by_owner( $form_id, $email ) : null;
			if ( $row ) {
				$token = self::reissue( $row, self::days( $definition ), 'client', 'link_sent' );
				self::mail_link( $row, $definition, $token, '' );
			}
			return Rest::respond( $is_js, $page_url, $form_id, 200, 'link_sent', $answer );
		} finally {
			Language::restore( $state );
		}
	}

	/**
	 * The email with the link: «here is your link» or, with a note from the
	 * team, «we need something else». In the dossier's language.
	 *
	 * @return array{to: string, status: string, error: string}
	 */
	public static function mail_link( array $row, array $definition, string $token, string $note ): array {
		$state = Language::switch_to( (string) $row['lang'] );
		try {
			$url     = self::url( self::page( (int) $row['post_id'], (string) $row['page_url'] ), $token );
			$title   = '' !== (string) $row['form_title'] ? (string) $row['form_title'] : (string) $row['form_id'];
			$missing = self::labels( $definition, (array) $row['missing'] );
			$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
			$lines   = array();
			if ( '' !== $note ) {
				/* translators: %s: form title. */
				$subject = sprintf( __( 'We need something else for «%s»', 'estrategiaweb-toolkit' ), $title );
				$lines[] = $note;
			} else {
				/* translators: %s: form title. */
				$subject = sprintf( __( 'Your link to «%s»', 'estrategiaweb-toolkit' ), $title );
				$lines[] = __( 'Here is the link to see and complete your request:', 'estrategiaweb-toolkit' );
			}
			if ( $missing ) {
				$lines[] = __( 'Still missing:', 'estrategiaweb-toolkit' ) . "\n- " . implode( "\n- ", $missing );
			}
			$days = self::days( $definition );
			/* translators: %d: number of days. */
			$lines[] = sprintf( _n( 'The link works for %d day. Do not forward it: whoever has it can see your request.', 'The link works for %d days. Do not forward it: whoever has it can see your request.', $days, 'estrategiaweb-toolkit' ), $days );
			$text = implode( "\n\n", $lines );
			$html = nl2br( esc_html( $text ), false ) . '<p><a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
			return Notifier::send_plain( (string) $row['owner_email'], $site . ': ' . $subject, $text . "\n\n" . $url, $html, (string) $row['lang'] );
		} finally {
			Language::restore( $state );
		}
	}

	/** GET /file?d=<token>&f=<id>: a file of the dossier, for its owner. */
	public static function file( \WP_REST_Request $request ): \WP_REST_Response {
		$row = self::load( (string) $request->get_param( 'd' ) );
		if ( ! $row ) {
			Rest::within_rate_limit( Rest::client_ip() ); // A wrong token counts against the limit.
			return new \WP_REST_Response( array( 'ok' => false ), 404 );
		}
		$file = Files::find( $row, (string) $request->get_param( 'f' ) );
		if ( ! $file ) {
			return new \WP_REST_Response( array( 'ok' => false ), 404 );
		}
		Files::download( $file ); // Ends the request.
		return new \WP_REST_Response( null, 204 );
	}

	public static function file_url( string $token, string $file_id ): string {
		return add_query_arg(
			array(
				'd' => $token,
				'f' => $file_id,
			),
			rest_url( Rest::NS . '/file' )
		);
	}
}
