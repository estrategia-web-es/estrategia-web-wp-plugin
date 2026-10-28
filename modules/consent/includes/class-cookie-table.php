<?php
/**
 * The COOKIE TABLE for the Cookie policy page: the cookies CONFIRMED in
 * «Estrategia Web → Cookies», grouped by category, with name, provider,
 * purpose and duration.
 *
 * Where it is used: the estrategia-web/cookie-table block (blocks/cookie-table,
 * no editor of its own: autoRegister) and the shortcode [estrategia_web_cookie_table
 * category="statistics" heading_level="3"], for whoever edits the policy
 * without blocks. Both print the same thing (render()).
 *
 * Translation, in three layers:
 * - The FIXED parts (table headings, name and description of each category):
 *   gettext, estrategia-web domain (the plugin's .po).
 * - The DURATION, if it has the form «2 years», «30 minutes», «session» (as
 *   it comes from the external database and from base()): written with _n()
 *   in the page language. If the administrator wrote something else («Until
 *   the browser closes»), it is treated as free text (next point).
 * - What the administrator WRITES (provider, purpose, free duration): WPML
 *   String Translation, context «estrategia-web-cookies», names
 *   «purpose <cookie>», «provider <cookie>», «duration <cookie>». They are
 *   registered on save (register_strings) and translated on render with
 *   wpml_translate_single_string. Without WPML, printed as is.
 *
 * How to maintain:
 * - Who and when: whoever touches the notice or the table. Test:
 *   modules/consent/tests/cookie-registry.test.mjs (level 2).
 * - A new duration unit: into duration() with its _n().
 * - Edge case: no confirmed cookie. On the site NOTHING is printed (an empty
 *   table in the policy would say «we use no cookies», and that is false: at
 *   the very least there is the notice's own). In the editor a note says why.
 * - The trap: WPML strings are registered ON SAVE, not on render. A row the
 *   detector created and nobody has saved on the screen is not in String
 *   Translation; it does not show in the table either (it is not confirmed),
 *   so it goes unnoticed. Confirm = save = register.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Cookie_Table {

	public const BLOCK         = 'estrategia-web/cookie-table';
	public const WPML_CONTEXT  = 'estrategia-web-cookies';
	public const SHORTCODE     = 'estrategia_web_cookie_table';

	/** Name and description of each category, for the table and the screen. */
	public static function category_labels(): array {
		$known  = Consent::known_categories();
		$labels = array(
			'necessary' => array(
				'label'       => __( 'Necessary', 'estrategia-web' ),
				'description' => __( 'Required for the site to work, including remembering this choice. Always active.', 'estrategia-web' ),
			),
		);
		foreach ( Cookie_Registry::CATEGORIES as $slug ) {
			if ( isset( $known[ $slug ] ) ) {
				$labels[ $slug ] = $known[ $slug ];
			}
		}
		return $labels;
	}

	/** Registers a row's free texts in WPML (when it is saved). */
	public static function register_strings( string $key, array $entry ): void {
		foreach ( array( 'provider', 'purpose', 'duration' ) as $field ) {
			if ( '' !== (string) $entry[ $field ] && ( 'duration' !== $field || null === self::duration_parts( (string) $entry[ $field ] ) ) ) {
				do_action( 'wpml_register_single_string', self::WPML_CONTEXT, $field . ' ' . $key, (string) $entry[ $field ] );
			}
		}
	}

	private static function translated( string $field, string $key, string $value ): string {
		return '' === $value ? '' : (string) apply_filters( 'wpml_translate_single_string', $value, self::WPML_CONTEXT, $field . ' ' . $key );
	}

	/**
	 * «2 years» → array( 2, 'year' ); «session» → array( 0, 'session' ); anything else → null.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	public static function duration_parts( string $value ): ?array {
		$value = strtolower( trim( $value ) );
		if ( 'session' === $value ) {
			return array( 0, 'session' );
		}
		if ( preg_match( '/^(\d+)\s*(second|minute|hour|day|week|month|year)s?$/', $value, $m ) ) {
			return array( (int) $m[1], $m[2] );
		}
		return null;
	}

	/** The duration in the page language (see the header). */
	public static function duration( string $key, string $value ): string {
		$parts = self::duration_parts( $value );
		if ( null === $parts ) {
			return self::translated( 'duration', $key, $value );
		}
		list( $n, $unit ) = $parts;
		switch ( $unit ) {
			case 'session':
				return __( 'Session', 'estrategia-web' );
			case 'second':
				/* translators: %d: number of seconds. */
				return sprintf( _n( '%d second', '%d seconds', $n, 'estrategia-web' ), $n );
			case 'minute':
				/* translators: %d: number of minutes. */
				return sprintf( _n( '%d minute', '%d minutes', $n, 'estrategia-web' ), $n );
			case 'hour':
				/* translators: %d: number of hours. */
				return sprintf( _n( '%d hour', '%d hours', $n, 'estrategia-web' ), $n );
			case 'day':
				/* translators: %d: number of days. */
				return sprintf( _n( '%d day', '%d days', $n, 'estrategia-web' ), $n );
			case 'week':
				/* translators: %d: number of weeks. */
				return sprintf( _n( '%d week', '%d weeks', $n, 'estrategia-web' ), $n );
			case 'month':
				/* translators: %d: number of months. */
				return sprintf( _n( '%d month', '%d months', $n, 'estrategia-web' ), $n );
			default:
				/* translators: %d: number of years. */
				return sprintf( _n( '%d year', '%d years', $n, 'estrategia-web' ), $n );
		}
	}

	/**
	 * The table HTML.
	 *
	 * @param array $attributes category ('' = all), headingLevel (2-6).
	 */
	public static function render( array $attributes ): string {
		$only    = (string) ( $attributes['category'] ?? '' );
		$level   = min( 6, max( 2, (int) ( $attributes['headingLevel'] ?? 3 ) ) );
		$groups  = Cookie_Registry::confirmed_by_category();
		$labels  = self::category_labels();
		if ( '' !== $only ) {
			$groups = array_intersect_key( $groups, array( $only => true ) );
		}
		if ( ! $groups ) {
			if ( wp_is_serving_rest_request() ) {
				return sprintf(
					'<p %s>%s</p>',
					get_block_wrapper_attributes(),
					esc_html__( 'Cookie table: there are no confirmed cookies yet. Confirm them in Estrategia Web → Cookies and they will appear here.', 'estrategia-web' )
				);
			}
			return '';
		}

		$html = '';
		foreach ( $groups as $category => $entries ) {
			$label = $labels[ $category ] ?? array(
				'label'       => $category,
				'description' => '',
			);
			$html .= sprintf( '<h%1$d class="estrategia-web-cookie-table__title">%2$s</h%1$d>', $level, esc_html( $label['label'] ) );
			if ( '' !== $label['description'] ) {
				$html .= '<p class="estrategia-web-cookie-table__description">' . esc_html( $label['description'] ) . '</p>';
			}
			$html .= '<figure class="wp-block-table estrategia-web-cookie-table__figure"><table class="estrategia-web-cookie-table__table"><thead><tr>';
			$html .= '<th scope="col">' . esc_html__( 'Cookie', 'estrategia-web' ) . '</th>';
			$html .= '<th scope="col">' . esc_html__( 'Provider', 'estrategia-web' ) . '</th>';
			$html .= '<th scope="col">' . esc_html__( 'Purpose', 'estrategia-web' ) . '</th>';
			$html .= '<th scope="col">' . esc_html__( 'Duration', 'estrategia-web' ) . '</th>';
			$html .= '</tr></thead><tbody>';
			foreach ( $entries as $key => $entry ) {
				$html .= sprintf(
					'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( (string) $key ),
					esc_html( self::translated( 'provider', (string) $key, (string) $entry['provider'] ) ),
					esc_html( self::translated( 'purpose', (string) $key, (string) $entry['purpose'] ) ),
					esc_html( self::duration( (string) $key, (string) $entry['duration'] ) )
				);
			}
			$html .= '</tbody></table></figure>';
		}
		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'class' => 'estrategia-web-cookie-table' ) ), $html );
	}

	/**
	 * [estrategia_web_cookie_table category="…" heading_level="3"].
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category'      => '',
				'heading_level' => 3,
			),
			$atts,
			self::SHORTCODE
		);
		return render_block(
			array(
				'blockName'    => self::BLOCK,
				'attrs'        => array(
					'category'     => (string) $atts['category'],
					'headingLevel' => (int) $atts['heading_level'],
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}
}
