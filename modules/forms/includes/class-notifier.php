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
 *   the reason, and the other notifications go out anyway.
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
	 * @param array{page_url: string, lang: string} $meta
	 * @return array<int, array{label: string, to: string, status: string, error: string}>
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

		$to_template = '' !== trim( (string) $n['to'] ) ? (string) $n['to'] : (string) get_option( 'admin_email' );
		$recipients  = array_values( array_filter( array_map( 'trim', preg_split( '/[,;]/', Markers::replace( $to_template, $markers['text'] ) ) ), 'is_email' ) );
		$to_display  = implode( ', ', $recipients );

		if ( ! $recipients ) {
			return array(
				'label'  => $label,
				'to'     => '',
				'status' => 'failed',
				'error'  => __( 'No valid recipient.', 'estrategia-web' ),
			);
		}

		$subject_template = '' !== trim( (string) $n['subject'] ) ? (string) $n['subject'] : '{{' . Markers::SITE_NAME . '}}: ' . ( $definition['title'] ?: __( 'New message', 'estrategia-web' ) );
		$subject          = Markers::single_line( Markers::replace( $subject_template, $markers['text'] ) );

		$body = '' !== trim( (string) $n['body'] ) ? (string) $n['body'] : '{{' . Markers::ALL_FIELDS . '}}';
		$text = Markers::replace( $body, $markers['text'] );
		$html = Markers::replace( nl2br( esc_html( $body ), false ), $markers['html'] );

		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply_to = Markers::single_line( Markers::replace( (string) $n['reply_to'], $markers['text'] ) );
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$mail = array(
			'to'      => $recipients,
			'subject' => $subject,
			'html'    => self::wrap_html( $html, $subject, $meta['lang'] ),
			'text'    => $text . self::text_footer( $meta['lang'] ),
			'headers' => $headers,
		);
		/**
		 * Last touch to a notification before sending it.
		 *
		 * @param array $mail       to, subject, html, text, headers.
		 * @param array $n          The notification configuration.
		 * @param array $definition The form definition.
		 */
		$mail = (array) apply_filters( 'estrategia_web_forms_email', $mail, $n, $definition );

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
			'error'  => $sent ? '' : ( '' !== $error ? $error : __( 'wp_mail() returned false.', 'estrategia-web' ) ),
		);
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

		$text = array_merge( $text, $fixed, array( Markers::ALL_FIELDS => rtrim( $all_text ) ) );
		foreach ( $fixed as $key => $value ) {
			$html[ $key ] = esc_html( $value );
		}
		$html[ Markers::ALL_FIELDS ] = $all_html;

		return array(
			'text' => Markers::with_aliases( $text ),
			'html' => Markers::with_aliases( $html ),
		);
	}

	private static function display_value( array $field, string $value ): string {
		if ( 'checkbox' === $field['type'] ) {
			return '1' === $value ? __( 'Yes', 'estrategia-web' ) : __( 'No', 'estrategia-web' );
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

	private static function text_footer( string $lang ): string {
		$footer = Settings::text( 'email_footer', $lang );
		return '' !== $footer ? "\n\n-- \n" . $footer : '';
	}
}
