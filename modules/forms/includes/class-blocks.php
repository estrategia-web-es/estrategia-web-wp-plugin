<?php
/**
 * Block registration and their public HTML.
 *
 * All blocks are DYNAMIC: PHP renders the HTML on every visit (the field
 * blocks store nothing in post_content but their attributes). That way a
 * markup change in the plugin reaches every site without «revalidating»
 * blocks in the editor, and the public HTML only contains what this file
 * decides to render: NEVER the notification attributes.
 *
 * Styling belongs to the theme. Only classes are set here: the plugin's
 * (estrategia-web-forms__*) for the minimal grid in assets/style.css, and
 * the standard WordPress ones (wp-element-button, wp-block-button__link) so
 * that theme.json dresses the button like any other.
 *
 * How to maintain:
 * - A new block: its folder in blocks/, its entry in BLOCKS, its edit in
 *   assets/editor.js, its texts in wpml-config.xml and, if it is a field, in
 *   Definitions::FIELD_BLOCKS and in Validator. In the same commit.
 * - The trap: inner blocks are rendered BEFORE the form, so they cannot
 *   read anything render_form() sets. What they need from the form reaches
 *   them through block context (estrategia-web-forms/formId).
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
		// Its translations (estrategia-web domain) are passed inline by core:
		// it is the editorScript of the nine blocks (includes/class-blocks.php
		// at the root). There is no longer a JSON with the md5 of the path.
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
		<form <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_block_wrapper_attributes. ?>
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
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML of the inner blocks, already escaped by each one. ?>
			</div>
			<div id="<?php echo esc_attr( $dom_id ); ?>-status" class="estrategia-web-forms__status" role="status" aria-live="polite" tabindex="-1"
				data-wp-text="context.message" data-wp-class--is-error="context.isError"><?php echo esc_html( $context['message'] ); ?></div>
		</form>
		<?php
		return (string) ob_get_clean() . self::announce( $dom_id, $result );
	}

	/**
	 * After a submission WITHOUT JavaScript that went well, the same signal
	 * view.js gives after a JS one: the «estrategia-web:form-sent» event, with
	 * detail.returned = true. Whoever measures conversions listens to it
	 * (modules/google-tags; it waits for consent there). ONCE per submission:
	 * the result is marked «announced» in its transient, so reloading the
	 * ?af_result=… URL (or opening it in another tab) does not count it again.
	 * Only the form id travels, never what was typed.
	 *
	 * Edge case: a browser that really has no JavaScript ignores the script;
	 * the submission is marked announced anyway (nobody could measure it).
	 */
	private static function announce( string $dom_id, ?array $result ): string {
		if ( null === $result || 'ok' !== ( $result['status'] ?? '' ) || ! empty( $result['announced'] ) ) {
			return '';
		}
		$key                                = sanitize_key( wp_unslash( $_GET[ self::RESULT_PARAM ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::$results[ $key ]['announced'] = true;
		set_transient( 'estrategia_web_forms_result_' . $key, self::$results[ $key ], 10 * MINUTE_IN_SECONDS );
		$detail = wp_json_encode(
			array(
				'form'     => $dom_id,
				'returned' => true,
			)
		);
		return wp_get_inline_script_tag( "document.dispatchEvent(new CustomEvent('estrategia-web:form-sent',{detail:{$detail}}));", array( 'class' => 'estrategia-web-forms-sent' ) );
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
	 * A field's autocomplete (0.2.2, WCAG 1.3.5): the block attribute or, if
	 * it is empty, «email» on the email field and «tel» on the phone field.
	 *
	 * - A text field without the attribute gets NO autocomplete: it is not
	 *   guessed from its name («nombre» may be a pet's name). Whoever builds
	 *   the form sets name, organization, given-name… (the values are those of
	 *   the HTML spec, «autofill detail tokens»).
	 * - Any list of lowercase words, digits and hyphens is accepted («work
	 *   tel», «shipping street-address», «off»). Anything else is discarded
	 *   entirely: that way there is no need to copy the spec's list here,
	 *   which keeps growing, and nothing slips into the HTML.
	 * - Edge case: «off» on an email field removes the default email.
	 * The trap: the attribute lives in post_content; a synced form
	 * (core/block) carries it in its own block, not in each page.
	 * Test: tests/autocomplete.test.mjs.
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

	/** Notifications are never rendered: they are configuration, not content. */
	public static function render_nothing(): string {
		return '';
	}

	/** The label in plain text. Empty = the default text, translated. */
	public static function field_label( string $type, array $attributes ): string {
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		if ( '' === $label && 'checkbox' === $type && ! empty( $attributes['consent'] ) ) {
			/* translators: the text between braces becomes the link to the privacy policy. */
			$label = __( 'I have read and accept the {privacy policy}.', 'estrategia-web' );
		}
		return str_replace( array( '{', '}' ), '', $label );
	}

	/**
	 * The consent label with its links inside: that way the sentence is
	 * translated whole, not in pieces.
	 *
	 * - {text}       links to the privacy policy (the block's privacyUrl or,
	 *                if there is none, the site's privacy page).
	 * - {text|url}   links to that url. There can be several in the same
	 *                sentence: «la {protección de datos|/proteccion/} y {Sus
	 *                datos seguros|/sus-datos/}». Born on 06-10-2026 at
	 *                Lavandería, which named two pages and got a single link
	 *                spanning both.
	 *
	 * A url that esc_url() leaves empty (javascript:, data:…) is not linked:
	 * the text remains. The trap: esc_url() is applied to the url ALREADY
	 * escaped by esc_html(); with «&» in the url the href gets «&amp;», which
	 * is correct.
	 */
	private static function consent_label_html( array $attributes ): string {
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		if ( '' === $label ) {
			/* translators: the text between braces becomes the link to the privacy policy; {text|url} links to that url. */
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
	 * The option that comes selected when there is no submission return. The
	 * order of the sources and the reason for it, in
	 * includes/class-preselect.php.
	 *
	 * @param string[] $options
	 */
	private static function preselected( string $name, array $options, array $attributes, \WP_Block $block ): string {
		$param = Preselect::URL_PREFIX . $name;
		$url   = isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only chooses among options that already exist.
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

	/** @return string[] One option per line, without empty or repeated ones. */
	public static function options( string $raw ): array {
		$lines = array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) );
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $lines ), 'strlen' ) ) );
	}

	/**
	 * The result of a submission without JavaScript, to render the form
	 * again with its message, its errors and what had been typed.
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
