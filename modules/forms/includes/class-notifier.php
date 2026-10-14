<?php
/**
 * Los avisos por correo: monta cada uno desde su plantilla y lo manda con
 * wp_mail(). Devuelve el resultado de cada aviso para el registro.
 *
 * Cada aviso sale en HTML (templates/email.php, que el tema puede sustituir
 * con su propio estrategia-web-forms/email.php) y con la versión en texto como
 * AltBody, la misma plantilla sin etiquetas.
 *
 * Se llama con el idioma del envío ya puesto (Language::switch_to), así que
 * los textos fijos —«Sí», la fecha, la tabla de campos— salen en el idioma
 * del visitante. Los textos de la plantilla ya vienen traducidos: son los de
 * la página que vio, traducida por WPML.
 *
 * Cómo se mantiene:
 * - Un marcador nuevo: entra en marker_values() y en el README («Marcadores»)
 *   y en la ayuda del editor (assets/editor.js), en el mismo commit.
 * - Caso frontera: si el destinatario sale de un campo y el visitante lo dejó
 *   vacío o mal, ese aviso consta como FALLO en el registro, con el motivo, y
 *   los demás avisos salen igual.
 * - La trampa: wp_mail() devuelve true si el correo se ENTREGÓ AL SERVIDOR
 *   de correo, no si llegó. «Enviado» en el registro significa eso. Si una web
 *   necesita saber más, es cosa de su SMTP, no de este plugin.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Notifier {

	/**
	 * @param array                 $definition La definición del formulario.
	 * @param array<string, string> $values     Valores ya validados.
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
		 * Último retoque de un aviso antes de enviarlo.
		 *
		 * @param array $mail       to, subject, html, text, headers.
		 * @param array $n          La configuración del aviso.
		 * @param array $definition La definición del formulario.
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
			'text' => $text,
			'html' => $html,
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
