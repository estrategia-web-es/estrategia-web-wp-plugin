<?php
/**
 * Mailchimp's Marketing API (3.0), only what the newsletter needs: create a
 * DRAFT. It never sends: there is no call to /actions/send or /actions/test
 * here, on purpose (the 04-09-2026 butlletí went out with last week's news
 * because nobody opened the test; the test is sent and OPENED in Mailchimp).
 *
 * create_draft(), in order:
 *   1. GET  /campaigns?status=sent… the last campaign sent (of list_id, if set): the model
 *   2. POST /campaigns              a NEW regular campaign WITHOUT template (Rules::campaign_body)
 *   3. PUT  /campaigns/{id}/content the html
 *   4. GET  /campaigns/{id}         checks it is not drag-and-drop; if it were, deletes it
 * If 3 or 4 fail the new campaign is DELETED: no half-made drafts are left.
 *
 * HTTP with wp_remote_request(), so the tests intercept it with
 * pre_http_request (modules/newsletter/tests/wp/): the tests never reach
 * the real Mailchimp.
 *
 * How to maintain:
 * - Who and when: whoever changes how the draft is made. A new call goes in
 *   create_draft() and in the mock of the test mu-plugin, in the same commit.
 * - Criterion: only what makes the draft. Statistics, audiences or sending
 *   are not this module's (atlas's bin/mailchimp.js does them by hand).
 * - Edge case, by name: an account whose last sent campaign is NOT the
 *   newsletter (a one-off announcement to another audience). Then set the
 *   Audience ID in the settings: the model is searched in that audience.
 * - The trap: Mailchimp answers 200 to a PUT /content on a drag-and-drop
 *   campaign and a GET gives that html back, but it sends the template. Step
 *   4 is what catches it, and it reads settings.drag_and_drop, not the html.
 */

namespace EstrategiaWeb\Newsletter;

defined( 'ABSPATH' ) || exit;

final class Mailchimp {

	private string $key;
	private string $dc;

	public function __construct( string $key ) {
		$this->key = $key;
		$this->dc  = (string) Rules::datacenter( $key );
	}

	public function ready(): bool {
		return '' !== $this->dc;
	}

	/**
	 * One request. Returns the decoded JSON (array) or WP_Error with Mailchimp's own message.
	 *
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $path, ?array $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'estrategia-web:' . $this->key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( 'https://' . $this->dc . '.api.mailchimp.com/3.0/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 ) {
			$detail = is_array( $json ) ? (string) ( $json['detail'] ?? $json['title'] ?? '' ) : '';
			/* translators: 1: HTTP status, 2: Mailchimp's message. */
			return new \WP_Error( 'estrategia_web_mailchimp', sprintf( __( 'Mailchimp answered %1$d: %2$s', 'estrategiaweb-toolkit' ), $code, $detail ) );
		}
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Creates the draft. Returns what the screen shows, or WP_Error.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_draft( string $subject, string $html, string $list_id = '' ) {
		if ( ! $this->ready() ) {
			return new \WP_Error( 'estrategia_web_mailchimp', __( 'There is no valid Mailchimp key in the settings.', 'estrategiaweb-toolkit' ) );
		}
		$query = array(
			'status'     => 'sent',
			'sort_field' => 'send_time',
			'sort_dir'   => 'DESC',
			'count'      => 1,
			'fields'     => 'campaigns.id,campaigns.send_time,campaigns.recipients,campaigns.settings',
		);
		if ( '' !== $list_id ) {
			$query['list_id'] = $list_id;
		}
		$found = $this->request( 'GET', 'campaigns?' . http_build_query( $query ) );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$model = $found['campaigns'][0] ?? null;
		if ( ! is_array( $model ) || empty( $model['recipients']['list_id'] ) ) {
			return new \WP_Error( 'estrategia_web_mailchimp', __( 'Mailchimp has no campaign sent to copy the audience and sender from.', 'estrategiaweb-toolkit' ) );
		}

		$created = $this->request( 'POST', 'campaigns', Rules::campaign_body( $model, $subject ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$id = (string) ( $created['id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'estrategia_web_mailchimp', __( 'Mailchimp did not return the new campaign.', 'estrategiaweb-toolkit' ) );
		}

		$put = $this->request( 'PUT', 'campaigns/' . rawurlencode( $id ) . '/content', array( 'html' => $html ) );
		if ( is_wp_error( $put ) ) {
			$this->request( 'DELETE', 'campaigns/' . rawurlencode( $id ) );
			return $put;
		}
		$check = $this->request( 'GET', 'campaigns/' . rawurlencode( $id ) . '?fields=id,web_id,status,settings.drag_and_drop,settings.subject_line,recipients.recipient_count' );
		if ( is_wp_error( $check ) || ! empty( $check['settings']['drag_and_drop'] ) ) {
			$this->request( 'DELETE', 'campaigns/' . rawurlencode( $id ) );
			return is_wp_error( $check ) ? $check : new \WP_Error( 'estrategia_web_mailchimp', __( 'Mailchimp made the campaign with a drag-and-drop template, which would send the template and not these news. It has been deleted.', 'estrategiaweb-toolkit' ) );
		}
		$web_id = (string) ( $check['web_id'] ?? $created['web_id'] ?? '' );
		return array(
			'id'         => $id,
			'status'     => (string) ( $check['status'] ?? '' ),
			'subject'    => (string) ( $check['settings']['subject_line'] ?? $subject ),
			'recipients' => (int) ( $check['recipients']['recipient_count'] ?? 0 ),
			'url'        => '' !== $web_id ? 'https://' . $this->dc . '.admin.mailchimp.com/campaigns/edit?id=' . rawurlencode( $web_id ) : 'https://' . $this->dc . '.admin.mailchimp.com/campaigns/',
			'model'      => array(
				'id'        => (string) ( $model['id'] ?? '' ),
				'subject'   => (string) ( $model['settings']['subject_line'] ?? '' ),
				'send_time' => (string) ( $model['send_time'] ?? '' ),
			),
		);
	}
}
