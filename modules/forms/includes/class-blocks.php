<?php
/**
 * Registro de los bloques y su HTML público.
 *
 * Todos los bloques son DINÁMICOS: el HTML lo pinta PHP en cada visita (los
 * de campo no guardan nada en post_content salvo sus atributos). Así un
 * cambio de marcado del plugin llega a todas las webs sin «revalidar» bloques
 * en el editor, y el HTML público solo contiene lo que este fichero decide
 * pintar: NUNCA los atributos de los avisos.
 *
 * El estilo es del tema. Aquí solo se ponen clases: las del plugin
 * (estrategia-web-forms__*) para la rejilla mínima de assets/style.css, y las
 * estándar de WordPress (wp-element-button, wp-block-button__link) para que
 * theme.json vista el botón como cualquier otro.
 *
 * Cómo se mantiene:
 * - Un bloque nuevo: su carpeta en blocks/, su entrada en BLOCKS, su edit en
 *   assets/editor.js, sus textos en wpml-config.xml y, si es un campo, en
 *   Definitions::FIELD_BLOCKS y en Validator. En el mismo commit.
 * - La trampa: los bloques interiores se pintan ANTES que el formulario, así
 *   que no pueden leer nada que ponga render_form(). Lo que necesitan del
 *   formulario les llega por contexto de bloque (estrategia-web-forms/formId).
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	public const BLOCKS = array(
		'form'         => 'render_form',
		'text'         => 'render_field',
		'email'        => 'render_field',
		'tel'          => 'render_field',
		'textarea'     => 'render_field',
		'select'       => 'render_field',
		'checkbox'     => 'render_field',
		'submit'       => 'render_submit',
		'notification' => 'render_nothing',
	);

	public const RESULT_PARAM = 'af_result';

	/** @var array<string, ?array> */
	private static array $results = array();

	public static function register(): void {
		wp_register_script(
			'estrategia-web-forms-editor',
			ESTRATEGIA_WEB_FORMS_URL . 'assets/editor.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-data' ),
			ESTRATEGIA_WEB_FORMS_VERSION,
			true
		);
		// Sus traducciones (dominio estrategia-web) se las pasa el núcleo en
		// línea: es el editorScript de los nueve bloques (includes/class-blocks.php
		// de la raíz). Ya no hay JSON con el md5 de la ruta.
		wp_register_style( 'estrategia-web-forms', ESTRATEGIA_WEB_FORMS_URL . 'assets/style.css', array(), ESTRATEGIA_WEB_FORMS_VERSION );
		wp_register_style( 'estrategia-web-forms-editor', ESTRATEGIA_WEB_FORMS_URL . 'assets/editor.css', array(), ESTRATEGIA_WEB_FORMS_VERSION );

		foreach ( self::BLOCKS as $dir => $callback ) {
			register_block_type( ESTRATEGIA_WEB_FORMS_DIR . 'blocks/' . $dir, array( 'render_callback' => array( self::class, $callback ) ) );
		}
	}

	public static function render_form( array $attributes, string $content, \WP_Block $block ): string {
		$definition = Definitions::build( $attributes, $block->parsed_block['innerBlocks'] ?? array() );
		$hash       = Definitions::store( $definition );
		$form_id    = $definition['form_id'];
		$dom_id     = 'af-' . ( '' !== $form_id ? $form_id : substr( $hash, 0, 8 ) );
		$result     = self::result( $form_id );

		$context = array(
			'message'  => $result['message'] ?? '',
			'isError'  => isset( $result['status'] ) && 'ok' !== $result['status'],
			'busy'     => false,
			'started'  => false,
			'tokenUrl'     => rest_url( 'estrategia-web-forms/v1/token' ),
			'networkError' => __( 'The message could not be sent. Please check your connection and try again.', 'estrategia-web' ),
		);

		$wrapper = get_block_wrapper_attributes(
			array(
				'class'  => 'estrategia-web-forms',
				'id'     => $dom_id,
				'action' => esc_url( rest_url( 'estrategia-web-forms/v1/submit' ) ),
				'method' => 'post',
			)
		);

		ob_start();
		?>
		<form <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado por get_block_wrapper_attributes. ?>
			data-wp-interactive="estrategia-web-forms"
			<?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			data-wp-on--submit="actions.submit"
			data-wp-on--focusin="actions.start">
			<input type="hidden" name="_af_def" value="<?php echo esc_attr( $hash ); ?>">
			<input type="hidden" name="_af_token" value="<?php echo esc_attr( Token::create( $hash, time(), wp_salt( 'nonce' ) ) ); ?>">
			<input type="hidden" name="_af_page" value="<?php echo esc_url( self::current_url() ); ?>">
			<div class="estrategia-web-forms__hp" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this field empty', 'estrategia-web' ); ?>
					<input type="text" name="af_website" value="" tabindex="-1" autocomplete="off">
				</label>
			</div>
			<div class="estrategia-web-forms__fields">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML de los bloques interiores, ya escapado por cada uno. ?>
			</div>
			<div id="<?php echo esc_attr( $dom_id ); ?>-status" class="estrategia-web-forms__status" role="status" aria-live="polite" tabindex="-1"
				data-wp-text="context.message" data-wp-class--is-error="context.isError"><?php echo esc_html( $context['message'] ); ?></div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_field( array $attributes, string $content, \WP_Block $block ): string {
		$type = Definitions::FIELD_BLOCKS[ $block->name ] ?? 'text';
		$name = Definitions::clean_id( (string) ( $attributes['name'] ?? '' ) );
		if ( '' === $name ) {
			return '';
		}
		$form_id  = (string) ( $block->context['estrategia-web-forms/formId'] ?? '' );
		$result   = self::result( $form_id );
		$id       = 'af-' . ( '' !== $form_id ? $form_id . '-' : '' ) . $name;
		$consent  = 'checkbox' === $type && ! empty( $attributes['consent'] );
		$required = $consent || ! empty( $attributes['required'] );
		$error    = $result['errors'][ $name ] ?? '';
		$value    = (string) ( $result['values'][ $name ] ?? '' );
		$width    = 'half' === ( $attributes['width'] ?? 'full' ) ? 'half' : 'full';

		$input_atts = array(
			'id'               => $id,
			'name'             => 'fields[' . $name . ']',
			'required'         => $required,
			'aria-describedby' => $id . '-error',
			'aria-invalid'     => '' !== $error ? 'true' : false,
		);
		$autocomplete = 'checkbox' === $type ? '' : self::autocomplete( $type, $attributes );
		if ( '' !== $autocomplete ) {
			$input_atts['autocomplete'] = $autocomplete;
		}
		$placeholder = (string) ( $attributes['placeholder'] ?? '' );
		if ( '' !== $placeholder && 'checkbox' !== $type && 'select' !== $type ) {
			$input_atts['placeholder'] = $placeholder;
		}

		$label_html = esc_html( self::field_label( $type, $attributes ) );
		if ( $consent ) {
			$label_html = self::consent_label_html( $attributes );
		}
		$mark = $required ? ' <span class="estrategia-web-forms__required" aria-hidden="true">*</span>' : '';

		ob_start();
		printf( '<div class="estrategia-web-forms__field estrategia-web-forms__field--%1$s estrategia-web-forms__field--%2$s">', esc_attr( $type ), esc_attr( $width ) );

		if ( 'checkbox' === $type ) {
			$input_atts += array( 'type' => 'checkbox', 'value' => '1', 'checked' => '1' === $value );
			echo '<input' . self::atts( $input_atts ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			printf( '<label for="%1$s" class="estrategia-web-forms__label">%2$s%3$s</label>', esc_attr( $id ), $label_html, $mark ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			printf( '<label for="%1$s" class="estrategia-web-forms__label">%2$s%3$s</label>', esc_attr( $id ), $label_html, $mark ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( 'textarea' === $type ) {
				echo '<textarea' . self::atts( $input_atts + array( 'rows' => 5 ) ) . '>' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'select' === $type ) {
				echo '<select' . self::atts( $input_atts ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$first = '' !== $placeholder ? $placeholder : __( 'Choose an option', 'estrategia-web' );
				echo '<option value="">' . esc_html( $first ) . '</option>';
				$options = self::options( (string) ( $attributes['options'] ?? '' ) );
				if ( null === $result ) {
					$value = self::preselected( $name, $options, $attributes, $block );
				}
				foreach ( $options as $option ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $option ), $value === $option ? ' selected' : '', esc_html( $option ) );
				}
				echo '</select>';
			} else {
				$extra = array(
					'type'  => $type,
					'value' => $value,
				);
				echo '<input' . self::atts( $input_atts + $extra ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		printf( '<p id="%1$s-error" class="estrategia-web-forms__error" data-af-error="%2$s">%3$s</p>', esc_attr( $id ), esc_attr( $name ), esc_html( $error ) );
		echo '</div>';
		return (string) ob_get_clean();
	}

	public static function render_submit( array $attributes ): string {
		$text = trim( (string) ( $attributes['text'] ?? '' ) );
		if ( '' === $text ) {
			$text = __( 'Send', 'estrategia-web' );
		}
		return sprintf(
			'<div class="estrategia-web-forms__field estrategia-web-forms__field--submit wp-block-button"><button type="submit" class="wp-block-button__link wp-element-button" data-wp-bind--disabled="context.busy">%s</button></div>',
			esc_html( $text )
		);
	}

	/**
	 * El autocomplete de un campo (0.2.2, WCAG 1.3.5): el atributo del bloque
	 * o, si está vacío, «email» en el de correo y «tel» en el de teléfono.
	 *
	 * - Un campo de texto sin el atributo NO lleva autocomplete: no se adivina
	 *   por su nombre («nombre» puede ser el de una mascota). Quien hace el
	 *   formulario pone name, organization, given-name… (los valores son los
	 *   de la especificación HTML, «autofill detail tokens»).
	 * - Se acepta cualquier lista de palabras en minúscula, cifras y guiones
	 *   («work tel», «shipping street-address», «off»). Lo demás se descarta
	 *   entero: así no hace falta copiar aquí la lista de la especificación,
	 *   que crece, y no se cuela nada en el HTML.
	 * - Caso frontera: «off» en un correo quita el email por defecto.
	 * La trampa: el atributo vive en post_content; un formulario sincronizado
	 * (core/block) lo lleva en su propio bloque, no en cada página.
	 * Prueba: tests/autocomplete.test.mjs.
	 */
	public static function autocomplete( string $type, array $attributes ): string {
		$value = strtolower( trim( (string) preg_replace( '/\s+/', ' ', (string) ( $attributes['autocomplete'] ?? '' ) ) ) );
		if ( '' !== $value && ! preg_match( '/^[a-z0-9-]+( [a-z0-9-]+)*$/', $value ) ) {
			$value = '';
		}
		if ( '' === $value ) {
			$value = array(
				'email' => 'email',
				'tel'   => 'tel',
			)[ $type ] ?? '';
		}
		return $value;
	}

	/** Los avisos no se pintan nunca: son configuración, no contenido. */
	public static function render_nothing(): string {
		return '';
	}

	/** La etiqueta en texto plano. Vacía = el texto por defecto, traducido. */
	public static function field_label( string $type, array $attributes ): string {
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		if ( '' === $label && 'checkbox' === $type && ! empty( $attributes['consent'] ) ) {
			/* translators: lo que va entre llaves se convierte en el enlace a la política de privacidad. */
			$label = __( 'I have read and accept the {privacy policy}.', 'estrategia-web' );
		}
		return str_replace( array( '{', '}' ), '', $label );
	}

	/**
	 * La etiqueta del consentimiento con sus enlaces dentro: así la frase se
	 * traduce entera, sin trozos.
	 *
	 * - {texto}      enlaza a la política de privacidad (privacyUrl del bloque
	 *                o, si no hay, la página de privacidad del sitio).
	 * - {texto|url}  enlaza a esa url. Puede haber varios en la misma frase:
	 *                «la {protección de datos|/proteccion/} y {Sus datos
	 *                seguros|/sus-datos/}». Nació el 06-10-2026 en Lavandería,
	 *                que nombraba dos páginas y salía un solo enlace con las dos.
	 *
	 * Una url que esc_url() deja vacía (javascript:, data:…) no se enlaza: queda
	 * el texto. La trampa: esc_url() se aplica a la url YA escapada por
	 * esc_html(); con «&» en la url sale «&amp;» en el href, que es lo correcto.
	 */
	private static function consent_label_html( array $attributes ): string {
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		if ( '' === $label ) {
			/* translators: lo que va entre llaves se convierte en el enlace a la política de privacidad; {texto|url} enlaza a esa url. */
			$label = __( 'I have read and accept the {privacy policy}.', 'estrategia-web' );
		}
		$privacy = trim( (string) ( $attributes['privacyUrl'] ?? '' ) );
		if ( '' === $privacy ) {
			$page_id = Language::object_id( (int) get_option( 'wp_page_for_privacy_policy' ), 'page', Language::current() );
			$privacy = $page_id && 'publish' === get_post_status( $page_id ) ? (string) get_permalink( $page_id ) : '';
		}
		return (string) preg_replace_callback(
			'/\{([^}|]+)(?:\|([^}]*))?\}/',
			static function ( $m ) use ( $privacy ) {
				$text = $m[1];
				$url  = isset( $m[2] ) ? esc_url( html_entity_decode( trim( $m[2] ) ) ) : esc_url( $privacy );
				if ( '' === $url ) {
					return $text;
				}
				return '<a href="' . $url . '" target="_blank" rel="noopener">' . $text . '</a>';
			},
			esc_html( $label )
		);
	}

	/**
	 * La opción que viene marcada cuando no hay vuelta de un envío. El orden
	 * de las fuentes y su porqué, en includes/class-preselect.php.
	 *
	 * @param string[] $options
	 */
	private static function preselected( string $name, array $options, array $attributes, \WP_Block $block ): string {
		$param = Preselect::URL_PREFIX . $name;
		$url   = isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo elige entre opciones que ya existen.
		$page  = $block->context['pattern/overrides'][ $name ]['defaultOption'] ?? null;
		return Preselect::pick(
			$options,
			array(
				$url,
				is_int( $page ) ? $page : null,
				(int) ( $attributes['defaultOption'] ?? 0 ),
			),
			'sanitize_title'
		);
	}

	/** @return string[] Una opción por línea, sin vacías ni repetidas. */
	public static function options( string $raw ): array {
		$lines = array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) );
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $lines ), 'strlen' ) ) );
	}

	/**
	 * El resultado de un envío sin JavaScript, para volver a pintar el
	 * formulario con su mensaje, sus errores y lo que se había escrito.
	 */
	public static function result( string $form_id ): ?array {
		if ( ! isset( $_GET[ self::RESULT_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}
		$key = sanitize_key( wp_unslash( $_GET[ self::RESULT_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! array_key_exists( $key, self::$results ) ) {
			$stored                = get_transient( 'estrategia_web_forms_result_' . $key );
			self::$results[ $key ] = is_array( $stored ) ? $stored : null;
		}
		$result = self::$results[ $key ];
		return ( $result && ( $result['form_id'] ?? '' ) === $form_id ) ? $result : null;
	}

	private static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		if ( '' === $host ) {
			return home_url( '/' );
		}
		return remove_query_arg( self::RESULT_PARAM, ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}

	/** @param array<string, mixed> $atts */
	private static function atts( array $atts ): string {
		$out = '';
		foreach ( $atts as $key => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			$out .= true === $value ? ' ' . $key : sprintf( ' %s="%s"', $key, esc_attr( (string) $value ) );
		}
		return $out;
	}
}
