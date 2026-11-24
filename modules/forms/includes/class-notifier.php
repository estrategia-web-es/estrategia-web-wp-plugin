<?php
/**
 * The email notifications: builds each one from its template and sends it
 * with wp_mail(). Returns the result of each notification for the log.
 *
 * Each notification goes out as HTML (templates/email.php, which the theme
 * can replace with its own estrategia-web-forms/email.php) with the text
 * version as AltBody, the same template without tags.
 *
 * It is called with the submission's language already set
 * (Language::switch_to), so the fixed texts —«Yes», the date, the fields
 * table— come out in the visitor's language. The template texts arrive
 * already translated: they are the ones from the page the visitor saw,
 * translated by WPML.
 *
 * How to maintain:
 * - A new marker: it goes into marker_values(), into the README
 *   («Marcadores») and into the editor help (assets/editor.js), in the same
 *   commit.
 * - Edge case: if the recipient comes from a field and the visitor left it
 *   empty or wrong, that notification is recorded as FAILED in the log, with
 *   the reason, and the other notifications go out anyway. An EMPTY «To»
 *   template is the administrator's email; a To that only had addresses
 *   that did not survive is not (an acknowledgement to an empty {{email}}
 *   must not land in the admin's inbox).
 * - Recipients (2.14.0, 24-11-2026): To, Cc, Bcc and Reply-To are all
 *   validated after their markers are replaced, address by address
 *   (Markers::addresses()); bad ones are dropped and the rest go. The Cc
 *   does not repeat the To, the Bcc neither. The edge case, by name: the
 *   obituary's condolences with Cc = {{post_field:correu_familia}} on an
 *   obituary without that email go to the funeral home alone, «sent».
 * - Who may choose a recipient: only the form's author, in the block. The
 *   visitor reaches one only through a FIELD marker the author put there
 *   ({{email}} in the acknowledgement's To or the team's Reply-To); the
 *   entry markers read the entry signed into the form, never the request.
 * - The trap: wp_mail() returns true if the email was HANDED TO THE mail
 *   SERVER, not if it arrived. «Sent» in the log means that. If a site needs
 *   to know more, that is up to its SMTP, not this plugin.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Notifier {

	/**
	 * @param array                 $definition The form definition.
	 * @param array<string, string> $values     Values already validated.
	 * @param array{page_url: string, lang: string, extra?: array, post?: ?\WP_Post} $meta «post»: the entry of the signed context, or null.
	 * @return array<int, array{label: string, to: string, status: string, error: string, cc: string, bcc: string}>
	 */
	public static function send_all( array $definition, array $values, array $meta ): array {
		$results = array();
		foreach ( (array) ( $definition['notifications'] ?? array() ) as $i => $notification ) {
			$results[] = self::send_one( (array) $notification, $definition, $values, $meta, (int) $i );
		}
		return $results;
	}

	private static function send_one( array $n, array $definition, array $values, array $meta, int $index ): array {
		$markers = self::marker_values( $definition, $values, $meta );
		$label   = '' !== $n['label'] ? $n['label'] : sprintf( 'notification-%d', $index + 1 );

		// Every address is validated AFTER its markers are replaced, one by one (Markers::addresses()).
		$to_template = '' !== trim( (string) $n['to'] ) ? (string) $n['to'] : (string) get_option( 'admin_email' );
		$address_of  = static fn( string $template, array $except = array() ): array => Markers::addresses( Markers::replace( $template, $markers['text'] ), 'is_email', $except );
		$recipients  = $address_of( $to_template );
		$cc          = $address_of( (string) ( $n['cc'] ?? '' ), $recipients );
		$bcc         = $address_of( (string) ( $n['bcc'] ?? '' ), array_merge( $recipients, $cc ) );
		$reply_to    = $address_of( (string) $n['reply_to'] );

		$subject_template = '' !== trim( (string) $n['subject'] ) ? (string) $n['subject'] : '{{' . Markers::SITE_NAME . '}}: ' . ( $definition['title'] ?: __( 'New message', 'estrategiaweb-toolkit' ) );
		$subject          = Markers::single_line( Markers::replace( $subject_template, $markers['text'] ) );

		$body = '' !== trim( (string) $n['body'] ) ? (string) $n['body'] : '{{' . Markers::ALL_FIELDS . '}}';
		$text = Markers::replace( $body, $markers['text'] );
		$html = Markers::replace( nl2br( esc_html( $body ), false ), $markers['html'] );

		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from() );
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . implode( ', ', $reply_to );
		}
		if ( $cc ) {
			$headers[] = 'Cc: ' . implode( ', ', $cc );
		}
		if ( $bcc ) {
			$headers[] = 'Bcc: ' . implode( ', ', $bcc );
		}

		$mail = array(
			'to'      => $recipients,
			'subject' => $subject,
			'html'    => self::wrap_html( $html, $subject, $meta['lang'] ),
			'text'    => $text . self::text_footer( $meta['lang'] ),
			'headers' => $headers,
		);
		/**
		 * Last touch to a notification before sending it. Since the entry
		 * markers (2.14.0) it runs EVEN when no To address survived: a
		 * site can still set one here. With «to» still empty afterwards the
		 * notification is logged as failed.
		 *
		 * @param array $mail       to, subject, html, text, headers (Cc, Bcc and Reply-To are headers).
		 * @param array $n          The notification configuration.
		 * @param array $definition The form definition.
		 */
		$mail       = (array) apply_filters( 'estrategia_web_forms_email', $mail, $n, $definition );
		$to_display = implode( ', ', (array) $mail['to'] );
		$copies     = array(
			'cc'  => implode( ', ', $cc ),
			'bcc' => implode( ', ', $bcc ),
		);

		if ( ! array_filter( (array) $mail['to'] ) ) {
			return array(
				'label'  => $label,
				'to'     => '',
				'status' => 'failed',
				'error'  => __( 'No valid recipient.', 'estrategiaweb-toolkit' ),
			) + $copies;
		}

		$error    = '';
		$on_fail  = static function ( \WP_Error $e ) use ( &$error ): void {
			$error = $e->get_error_message();
		};
		$alt_body = static function ( $phpmailer ) use ( $mail ): void {
			$phpmailer->AltBody = $mail['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'wp_mail_failed', $on_fail );
		add_action( 'phpmailer_init', $alt_body );
		$sent = wp_mail( $mail['to'], $mail['subject'], $mail['html'], $mail['headers'] );
		remove_action( 'wp_mail_failed', $on_fail );
		remove_action( 'phpmailer_init', $alt_body );

		return array(
			'label'  => $label,
			'to'     => $to_display,
			'status' => $sent ? 'sent' : 'failed',
			'error'  => $sent ? '' : ( '' !== $error ? $error : __( 'wp_mail() returned false.', 'estrategiaweb-toolkit' ) ),
		) + $copies;
	}

	/**
	 * @return array{text: array<string, string>, html: array<string, string>}
	 */
	public static function marker_values( array $definition, array $values, array $meta ): array {
		$text = array();
		$html = array();
		$rows = array();

		foreach ( (array) $definition['fields'] as $field ) {
			$display = self::display_value( $field, (string) ( $values[ $field['name'] ] ?? '' ) );
			$text[ $field['name'] ] = $display;
			$html[ $field['name'] ] = nl2br( esc_html( $display ), false );
			$rows[]                 = array( (string) $field['label'], $display );
		}

		$all_text = '';
		$all_html = '<table class="fields" role="presentation" style="border-collapse:collapse;width:100%">';
		foreach ( $rows as [ $label, $display ] ) {
			$all_text .= $label . ': ' . ( str_contains( $display, "\n" ) ? "\n" . $display : $display ) . "\n";
			$all_html .= '<tr><th style="text-align:left;vertical-align:top;padding:6px 12px 6px 0;border-bottom:1px solid #e5e5e5">' . esc_html( $label ) . '</th>'
				. '<td style="padding:6px 0;border-bottom:1px solid #e5e5e5">' . nl2br( esc_html( $display ), false ) . '</td></tr>';
		}
		$all_html .= '</table>';

		$fixed = array(
			Markers::DATE      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			Markers::PAGE_URL  => (string) $meta['page_url'],
			Markers::SITE_NAME => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
		);

		// 2.11.0: the dossier's link and what is missing. Always known (empty in a plain form), so
		// that a template copied from a dossier form does not show «{{dossier_url}}» verbatim.
		$extra = array_merge(
			array(
				Markers::DOSSIER_URL => '',
				Markers::MISSING     => '',
			),
			array_intersect_key( (array) ( $meta['extra'] ?? array() ), array_flip( array( Markers::DOSSIER_URL, Markers::MISSING ) ) )
		);
		$fixed = array_merge( $fixed, $extra, self::entry_values( $definition, $meta['post'] ?? null ) );
		$text  = array_merge( $text, $fixed, array( Markers::ALL_FIELDS => rtrim( $all_text ) ) );
		foreach ( $fixed as $key => $value ) {
			$html[ $key ] = esc_html( $value );
		}
		if ( '' !== $extra[ Markers::DOSSIER_URL ] ) {
			$html[ Markers::DOSSIER_URL ] = '<a href="' . esc_url( $extra[ Markers::DOSSIER_URL ] ) . '">' . esc_html( $extra[ Markers::DOSSIER_URL ] ) . '</a>';
		}
		$html[ Markers::ALL_FIELDS ] = $all_html;

		return array(
			'text' => $text,
			'html' => $html,
		);
	}

	/**
	 * The entry markers (class-markers.php): the title, the link and the
	 * {{post_field:<key>}} that the form's notifications use, read from the
	 * entry whose id came SIGNED with the form (Rest::context_post()). Without
	 * one, all of them are known and empty, so a template never shows
	 * «{{post_title}}» verbatim and the submission goes on.
	 *
	 * @return array<string, string>
	 */
	private static function entry_values( array $definition, ?\WP_Post $post ): array {
		$templates = array();
		foreach ( (array) ( $definition['notifications'] ?? array() ) as $n ) {
			foreach ( array( 'to', 'cc', 'bcc', 'reply_to', 'subject', 'body' ) as $key ) {
				$templates[] = (string) ( $n[ $key ] ?? '' );
			}
		}
		$out = array(
			Markers::POST_TITLE => $post ? wp_specialchars_decode( wp_strip_all_tags( (string) get_the_title( $post ) ), ENT_QUOTES ) : '',
			Markers::POST_URL   => $post ? (string) get_permalink( $post ) : '',
		);
		$registered = $post ? array_keys( get_registered_meta_keys( 'post', $post->post_type ) + get_registered_meta_keys( 'post' ) ) : array();
		foreach ( Markers::post_field_keys( ...$templates ) as $key ) {
			$meta_key = $post ? Markers::post_field_key( $key, $post->post_type, $registered, static fn( string $k ): bool => is_protected_meta( $k, 'post' ) ) : '';
			$value    = '' !== $meta_key ? get_post_meta( $post->ID, $meta_key, true ) : '';
			$out[ Markers::POST_FIELD . ':' . $key ] = is_scalar( $value ) ? (string) $value : ''; // An array (a repeater, a gallery) has no text form here.
		}
		return $out;
	}

	/**
	 * One email that is not a form notification (2.11.0: the dossier link,
	 * «we need something else»), dressed with the same template.
	 *
	 * @return array{to: string, status: string, error: string}
	 */
	public static function send_plain( string $to, string $subject, string $text, string $html, string $lang ): array {
		if ( ! is_email( $to ) ) {
			return array(
				'to'     => $to,
				'status' => 'failed',
				'error'  => __( 'No valid recipient.', 'estrategiaweb-toolkit' ),
			);
		}
		$subject  = Markers::single_line( $subject );
		$text    .= self::text_footer( $lang );
		$error    = '';
		$on_fail  = static function ( \WP_Error $e ) use ( &$error ): void {
			$error = $e->get_error_message();
		};
		$alt_body = static function ( $phpmailer ) use ( $text ): void {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'wp_mail_failed', $on_fail );
		add_action( 'phpmailer_init', $alt_body );
		$sent = wp_mail( $to, $subject, self::wrap_html( $html, $subject, $lang ), array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from() ) );
		remove_action( 'wp_mail_failed', $on_fail );
		remove_action( 'phpmailer_init', $alt_body );
		return array(
			'to'     => $to,
			'status' => $sent ? 'sent' : 'failed',
			'error'  => $sent ? '' : ( '' !== $error ? $error : __( 'wp_mail() returned false.', 'estrategiaweb-toolkit' ) ),
		);
	}

	private static function display_value( array $field, string $value ): string {
		if ( 'checkbox' === $field['type'] ) {
			return '1' === $value ? __( 'Yes', 'estrategiaweb-toolkit' ) : __( 'No', 'estrategiaweb-toolkit' );
		}
		return $value;
	}

	private static function wrap_html( string $content_html, string $subject, string $lang ): string {
		$template = locate_template( 'estrategia-web-forms/email.php' );
		if ( '' === $template ) {
			$template = ESTRATEGIA_WEB_FORMS_DIR . 'templates/email.php';
		}
		$footer    = Settings::text( 'email_footer', $lang );
		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	/**
	 * «Site name <address>». The NAME is the site's: without it WordPress signs
	 * as «WordPress», which is what the client's team and the visitor saw in
	 * their inbox (Lavandería, 22-10-2026). The ADDRESS is WordPress's own
	 * default, wordpress@ + the domain without www: it is the one the host's
	 * SPF already covers, so nothing changes for deliverability. An SMTP
	 * plugin's wp_mail_from / wp_mail_from_name filters still win, because
	 * wp_mail() applies them after reading this header.
	 */
	public static function from(): string {
		$name = trim( str_replace( array( '"', '<', '>', ',', ';' ), '', Markers::single_line( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ) ) );
		$host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		$host = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$from = 'wordpress@' . $host;
		return '' !== $name ? $name . ' <' . $from . '>' : $from;
	}

	private static function text_footer( string $lang ): string {
		$footer = Settings::text( 'email_footer', $lang );
		return '' !== $footer ? "\n\n-- \n" . $footer : '';
	}
}
