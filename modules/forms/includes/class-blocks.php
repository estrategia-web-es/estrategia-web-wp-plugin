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
		'file'         => 'render_field', // 2.11.0.
		'submit'       => 'render_submit',
		'notification' => 'render_nothing',
		'form-ref'     => 'render_form_ref', // 2.0.0: a form post (eweb_form) shown in a page.
	);

	public const RESULT_PARAM = 'af_result';

	/** @var array<string, ?array> */
	private static array $results = array();

	public static function register(): void {
		wp_register_script(
			'estrategia-web-forms-editor',
			ESTRATEGIA_WEB_FORMS_URL . 'assets/editor.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-data', 'wp-core-data', 'wp-server-side-render', 'wp-url' ),
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

		// 2.11.0, dossier mode: the link in the URL, if it opens a dossier of this form.
		$is_dossier = ! empty( $definition['dossier'] );
		$token      = $is_dossier ? Dossier::token_in_url() : '';
		$row        = '' !== $token ? Dossier::load( $token, $form_id ) : null;
		$has_files  = in_array( 'file', array_column( $definition['fields'], 'type' ), true );
		$status     = (string) ( $result['status'] ?? '' );

		$context = array(
			'message'  => $result['message'] ?? '',
			'isError'  => '' !== $status && ! in_array( $status, array( 'ok', 'link_sent' ), true ),
			'sent'     => 'ok' === $status && ! $is_dossier, // A dossier stays open after saving: it is still being filled in.
			'busy'     => false,
			'started'  => false,
			'tokenUrl'     => rest_url( 'estrategia-web-forms/v1/token' ),
			'networkError' => __( 'The message could not be sent. Please check your connection and try again.', 'estrategiaweb-toolkit' ),
		);

		$wrapper = get_block_wrapper_attributes(
			array(
				'class'  => 'estrategia-web-forms',
				'id'     => $dom_id,
				'action' => esc_url( rest_url( 'estrategia-web-forms/v1/submit' ) ),
				'method' => 'post',
			) + ( $has_files ? array( 'enctype' => 'multipart/form-data' ) : array() )
		);

		ob_start();
		?>
		<form <?php echo wp_kses_data( $wrapper ); ?>
			data-wp-interactive="estrategia-web-forms"
			<?php echo wp_kses_data( wp_interactivity_data_wp_context( $context ) ); ?>
			data-wp-class--is-sent="context.sent"
			data-wp-on--submit="actions.submit"
			data-wp-on--focusin="actions.start">
			<input type="hidden" name="_af_def" value="<?php echo esc_attr( $hash ); ?>">
			<input type="hidden" name="_af_token" value="<?php echo esc_attr( Token::create( $hash, time(), wp_salt( 'nonce' ) ) ); ?>">
			<input type="hidden" name="_af_page" value="<?php echo esc_url( self::current_url() ); ?>">
			<?php if ( $row ) : ?>
				<input type="hidden" name="_af_dossier" value="<?php echo esc_attr( $token ); ?>">
			<?php endif; ?>
			<div class="estrategia-web-forms__hp" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this field empty', 'estrategiaweb-toolkit' ); ?>
					<input type="text" name="af_website" value="" tabindex="-1" autocomplete="off">
				</label>
			</div>
			<?php echo wp_kses( self::dossier_notice( $definition, $token, $row ), \EstrategiaWeb\Kses::allowed() ); ?>
			<div class="estrategia-web-forms__fields">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML of the inner blocks, already escaped by each one. ?>
			</div>
			<div id="<?php echo esc_attr( $dom_id ); ?>-status" class="estrategia-web-forms__status" role="status" aria-live="polite" tabindex="-1"
				data-wp-text="context.message" data-wp-class--is-error="context.isError" data-wp-class--is-success="context.sent"><?php echo esc_html( $context['message'] ); ?></div>
		</form>
		<?php
		$link_form = $is_dossier && ! $row ? self::dossier_link_form( $hash, $dom_id, '' !== $token ) : '';
		return (string) ob_get_clean() . $link_form . self::announce( $dom_id, $result );
	}

	/**
	 * Above the fields of a dossier form (2.11.0): where the visitor stands.
	 * Opened with a valid link: what is still missing, or that it is
	 * complete. With a link that does not work: that it does not, and that
	 * a new one can be asked for below. Nothing on a plain visit.
	 */
	private static function dossier_notice( array $definition, string $token, ?array $row ): string {
		if ( empty( $definition['dossier'] ) || '' === $token ) {
			return '';
		}
		if ( ! $row ) {
			return '<p class="estrategia-web-forms__notice estrategia-web-forms__notice--error">' . esc_html__( 'This link has expired or is no longer valid. You can ask for a new one below the form.', 'estrategiaweb-toolkit' ) . '</p>';
		}
		$missing = Dossier::labels( $definition, (array) $row['missing'] );
		$text    = $missing
			/* translators: %s: the labels of the fields still missing, separated by commas. */
			? sprintf( __( 'You are completing your request. Still missing: %s. Save whenever you want: what you have sent is kept.', 'estrategiaweb-toolkit' ), implode( ', ', $missing ) )
			: __( 'Your request is complete. You can review it and change whatever you need.', 'estrategiaweb-toolkit' );
		return '<p class="estrategia-web-forms__notice">' . esc_html( $text ) . '</p>';
	}

	/**
	 * «Send me my link» (2.11.0), under a dossier form opened without a
	 * valid link. Its own <form> (forms cannot be nested), posting to
	 * /dossier-link with the same definition, token and honeypot as the main
	 * one; without JavaScript it comes back like a submission (303 with
	 * ?af_result) and the answer shows in the main form's message area.
	 */
	private static function dossier_link_form( string $hash, string $dom_id, bool $open ): string {
		$id = $dom_id . '-link';
		ob_start();
		?>
		<details class="estrategia-web-forms__link"<?php echo $open ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Already started? We will send you your link', 'estrategiaweb-toolkit' ); ?></summary>
			<?php // The same classes as the main form: the plugin's and the theme's styles hang from them (seen unstyled on a phone, 20-11-2026). ?>
			<form class="estrategia-web-forms estrategia-web-forms__link-form" method="post" action="<?php echo esc_url( rest_url( Rest::NS . '/dossier-link' ) ); ?>">
				<input type="hidden" name="_af_def" value="<?php echo esc_attr( $hash ); ?>">
				<input type="hidden" name="_af_token" value="<?php echo esc_attr( Token::create( $hash, time(), wp_salt( 'nonce' ) ) ); ?>">
				<input type="hidden" name="_af_page" value="<?php echo esc_url( self::current_url() ); ?>">
				<div class="estrategia-web-forms__hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this field empty', 'estrategiaweb-toolkit' ); ?>
						<input type="text" name="af_website" value="" tabindex="-1" autocomplete="off">
					</label>
				</div>
				<div class="estrategia-web-forms__fields">
					<div class="estrategia-web-forms__field estrategia-web-forms__field--email estrategia-web-forms__field--full">
						<label for="<?php echo esc_attr( $id ); ?>" class="estrategia-web-forms__label"><?php esc_html_e( 'The email you used', 'estrategiaweb-toolkit' ); ?></label>
						<input id="<?php echo esc_attr( $id ); ?>" type="email" name="af_dossier_email" required autocomplete="email">
					</div>
					<div class="estrategia-web-forms__field estrategia-web-forms__field--submit wp-block-button"><button type="submit" class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Send me the link', 'estrategiaweb-toolkit' ); ?></button></div>
				</div>
			</form>
		</details>
		<?php
		return (string) ob_get_clean();
	}

	/** The dossier the URL's link opens for this form, or null (2.11.0). */
	private static function dossier_row( string $form_id ): ?array {
		$token = Dossier::token_in_url();
		return '' !== $token ? Dossier::load( $token, $form_id ) : null;
	}

	/**
	 * The «Formulario» reference block (2.0.0): renders the form post it
	 * points to, in the current language (Form_Post_Type::resolve()),
	 * EXACTLY as the same tree rendered inline: the post's blocks become
	 * this block's inner blocks and are rendered as such, the way core's
	 * synced pattern (core/block) does it in WordPress 7.1. Not with
	 * do_blocks() or render_block() on the side, and that is the point:
	 * - the context reaches the form's blocks. A reference inside a synced
	 *   pattern gets the pattern's «pattern/overrides» (core/block PROVIDES it
	 *   to its inner blocks since 7.x, it is no longer a global filter), and
	 *   the dropdown's per-page preselection (class-preselect.php, source 3)
	 *   keeps working. Lavandería's quote form lives exactly like that.
	 * - The Interactivity API, the snapshot (Definitions, post_id = the page
	 *   being viewed), the token, the honeypot, the no-JS return: all of it
	 *   is render_form(), untouched.
	 * Nothing (empty string) when the reference points nowhere: no form, not
	 * published, or the same form already being rendered higher up (a loop).
	 * In the editor the block shows its own picker (assets/editor.js).
	 */
	public static function render_form_ref( array $attributes, string $content, \WP_Block $block ): string {
		static $rendering = array();
		$id = Form_Post_Type::resolve( (int) ( $attributes['ref'] ?? 0 ) );
		if ( ! $id || isset( $rendering[ $id ] ) ) {
			return '';
		}
		$rendering[ $id ] = true;

		$block->parsed_block['innerBlocks']  = parse_blocks( (string) get_post_field( 'post_content', $id, 'raw' ) );
		$block->parsed_block['innerContent'] = array_fill( 0, count( $block->parsed_block['innerBlocks'] ), null );
		$block->refresh_context_dependents();
		$html = $block->render( array( 'dynamic' => false ) );

		unset( $rendering[ $id ] );
		return $html;
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

		// 2.11.0, dossier mode: what the dossier holds, unless a no-JS return just brought what was typed;
		// and the browser only insists on what a SAVE needs (Dossier::needed_to_save()), not on all the «*».
		$in_dossier = ! empty( $block->context['estrategia-web-forms/dossier'] );
		$row        = $in_dossier ? self::dossier_row( $form_id ) : null;
		$stored     = array();
		foreach ( $row ? (array) $row['data'] : array() as $item ) {
			if ( ( $item['name'] ?? '' ) === $name ) {
				$stored = $item;
			}
		}
		if ( $stored && null === $result ) {
			$value = (string) ( $stored['value'] ?? '' );
		}
		$browser_required = $in_dossier ? ( $consent || ! empty( $attributes['requiredToSave'] ) ) : $required;
		if ( 'file' === $type ) {
			$browser_required = $browser_required && empty( $stored['files'] );
		}

		// 2.11.0: the help line under the field (instructions, where to find the data). The placeholder
		// is for an EXAMPLE only: it disappears on typing and many screen readers do not read it.
		$help = self::field_help( $type, $attributes );

		$input_atts = array(
			'id'               => $id,
			'name'             => 'file' === $type ? 'files[' . $name . '][]' : 'fields[' . $name . ']',
			'required'         => $browser_required,
			'aria-describedby' => ( '' !== $help ? $id . '-help ' : '' ) . $id . '-error',
			'aria-invalid'     => '' !== $error ? 'true' : false,
		);
		$autocomplete = 'checkbox' === $type || 'file' === $type ? '' : self::autocomplete( $type, $attributes );
		if ( '' !== $autocomplete ) {
			$input_atts['autocomplete'] = $autocomplete;
		}
		$placeholder = (string) ( $attributes['placeholder'] ?? '' );
		if ( '' !== $placeholder && ! in_array( $type, array( 'checkbox', 'select', 'file' ), true ) ) {
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
			echo '<input' . wp_kses_data( self::atts( $input_atts ) ) . '>';
			printf( '<label for="%1$s" class="estrategia-web-forms__label">%2$s%3$s</label>', esc_attr( $id ), wp_kses_post( $label_html ), wp_kses_post( $mark ) );
		} else {
			printf( '<label for="%1$s" class="estrategia-web-forms__label">%2$s%3$s</label>', esc_attr( $id ), wp_kses_post( $label_html ), wp_kses_post( $mark ) );
			if ( 'file' === $type ) {
				echo wp_kses( self::file_input( $attributes, $input_atts, (array) ( $stored['files'] ?? array() ) ), \EstrategiaWeb\Kses::allowed() );
			} elseif ( 'textarea' === $type ) {
				echo '<textarea' . wp_kses_data( self::atts( $input_atts + array( 'rows' => 5 ) ) ) . '>' . esc_textarea( $value ) . '</textarea>';
			} elseif ( 'select' === $type ) {
				echo '<select' . wp_kses_data( self::atts( $input_atts ) ) . '>';
				$first = '' !== $placeholder ? $placeholder : __( 'Choose an option', 'estrategiaweb-toolkit' );
				echo '<option value="">' . esc_html( $first ) . '</option>';
				$options = self::options( (string) ( $attributes['options'] ?? '' ) );
				if ( null === $result && ! $stored ) {
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
				echo '<input' . wp_kses_data( self::atts( $input_atts + $extra ) ) . '>';
			}
		}
		if ( '' !== $help ) {
			printf( '<p id="%1$s-help" class="estrategia-web-forms__help">%2$s</p>', esc_attr( $id ), esc_html( $help ) );
		}
		printf( '<p id="%1$s-error" class="estrategia-web-forms__error" data-af-error="%2$s">%3$s</p>', esc_attr( $id ), esc_attr( $name ), esc_html( $error ) );
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * The file input (2.11.0): the files the dossier already holds (each
	 * with its download link, for its owner, and a «Remove» box), the input
	 * and a line saying what is accepted.
	 *
	 * @param array<string, mixed> $input_atts
	 * @param array<int, array>    $files
	 */
	private static function file_input( array $attributes, array $input_atts, array $files ): string {
		$types = Files::accepted( (string) ( $attributes['accept'] ?? '' ) );
		$token = Dossier::token_in_url();
		$out   = '';
		if ( $files ) {
			$out .= '<ul class="estrategia-web-forms__files">';
			foreach ( $files as $file ) {
				$out .= sprintf(
					'<li><a href="%1$s">%2$s</a> <span class="estrategia-web-forms__file-size">(%3$s)</span> <label class="estrategia-web-forms__remove"><input type="checkbox" name="af_remove[]" value="%4$s"> %5$s</label></li>',
					esc_url( Dossier::file_url( $token, (string) $file['id'] ) ),
					esc_html( (string) $file['name'] ),
					esc_html( size_format( (int) $file['size'] ) ),
					esc_attr( (string) $file['id'] ),
					esc_html__( 'Remove', 'estrategiaweb-toolkit' )
				);
			}
			$out .= '</ul>';
		}
		$input_atts += array(
			'type'   => 'file',
			'accept' => Files::accept_attribute( $types ),
		);
		if ( ! empty( $attributes['multiple'] ) ) {
			$input_atts['multiple'] = true;
		}
		return $out . '<input' . self::atts( $input_atts ) . '>';
	}

	/**
	 * The help line of a field: its «Help» attribute; a File field without
	 * one says what it accepts. '' = no line (and no aria-describedby to it).
	 */
	public static function field_help( string $type, array $attributes ): string {
		$help = trim( (string) ( $attributes['help'] ?? '' ) );
		if ( '' === $help && 'file' === $type ) {
			/* translators: 1: accepted types, e.g. «PDF, JPG, PNG»; 2: maximum size in MB. */
			$help = sprintf( __( '%1$s. Up to %2$d MB per file.', 'estrategiaweb-toolkit' ), strtoupper( implode( ', ', Files::accepted( (string) ( $attributes['accept'] ?? '' ) ) ) ), max( 1, (int) ( $attributes['maxMb'] ?? Files::DEFAULT_MAX_MB ) ) );
		}
		return $help;
	}

	public static function render_submit( array $attributes ): string {
		$text = trim( (string) ( $attributes['text'] ?? '' ) );
		if ( '' === $text ) {
			$text = __( 'Send', 'estrategiaweb-toolkit' );
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
			$label = __( 'I have read and accept the {privacy policy}.', 'estrategiaweb-toolkit' );
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
			$label = __( 'I have read and accept the {privacy policy}.', 'estrategiaweb-toolkit' );
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
