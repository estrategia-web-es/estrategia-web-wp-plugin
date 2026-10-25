<?php
/**
 * estrategia-web/language-switcher — language switcher (0.7.6).
 *
 * What it renders: a BUTTON with the current language («ES» and an arrow)
 * and, when pressed, a list of links to the SAME page in the other
 * languages:
 *
 *     <div class="estrategia-web-language">
 *       <button aria-expanded="false" aria-controls="…">ES</button>
 *       <ul id="…" hidden><li><a href="/en/…" hreflang="en" lang="en">English</a></li></ul>
 *     </div>
 *
 * The languages and their URLs come from WPML (wpml_active_languages
 * filter). WITHOUT WPML, or with only one active language, NOTHING IS
 * RENDERED: a switcher with one language is useless, and a monolingual site
 * has no reason to know this block exists.
 *
 * Decisions:
 * - A button with aria-expanded and a list of links, not a <select>:
 *   switching language is navigating, and a link with hreflang and lang is
 *   what a screen reader and a search engine understand. And not <details>:
 *   its <summary> doesn't announce «expanded/collapsed» in every screen
 *   reader, which is why Lavandería's child theme had one and it was
 *   replaced by this.
 * - Interactivity API (view.js), like the parent's other blocks: opens and
 *   closes, closes with Escape (and focus returns to the button) and on
 *   clicking outside. Without JavaScript the list doesn't open; the current
 *   language is still visible.
 * - Each link carries the language's name IN THAT LANGUAGE («English», not
 *   «Inglés»): that is how someone looking for their language recognises it.
 * - skip_missing 0: if the page isn't translated, WPML gives that language's
 *   front page. Better to land on the English front page than not to be able
 *   to switch.
 * - No brand styles: the stylesheet only positions the list. The child sets
 *   the look on .estrategia-web-language (Lavandería: theme/assets/css/header.css),
 *   the arrow included (.estrategia-web-language__toggle::after).
 *
 * How to maintain:
 * - Who: whoever touches the header of a site with WPML. When: adding a
 *   language needs no change (it comes from WPML); when changing the markup,
 *   in the same commit, the module's tests/language-switcher*.test.mjs (and
 *   the header selector of a child theme that styles it, like Lavandería's
 *   tests/language-switcher-html.test.mjs).
 * - Edge case: the code shown is WPML's in upper case («ES»); if a site wants
 *   «Español», attribute display: name. Flags NO: they are countries, not
 *   languages.
 * - The trap: in the editor (ServerSideRender, a REST request) WPML may say
 *   there are no languages and the block comes out empty. It's not a bug: on
 *   the site it does show. And with two blocks on the same page, the list id
 *   is made distinct with wp_unique_id(); never a fixed id.
 *
 * @package estrategia-web
 */

defined( 'ABSPATH' ) || exit;

$estrategia_web_languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
if ( ! is_array( $estrategia_web_languages ) || count( $estrategia_web_languages ) < 2 ) {
	return;
}

$estrategia_web_current = null;
$estrategia_web_others  = array();
foreach ( $estrategia_web_languages as $estrategia_web_language ) {
	if ( ! empty( $estrategia_web_language['active'] ) ) {
		$estrategia_web_current = $estrategia_web_language;
	} else {
		$estrategia_web_others[] = $estrategia_web_language;
	}
}
if ( ! $estrategia_web_current || ! $estrategia_web_others ) {
	return;
}

$estrategia_web_name    = static fn( array $l ): string => (string) ( $l['native_name'] ?? $l['translated_name'] ?? strtoupper( (string) $l['code'] ) );

// «inline» (0.7.7): all languages on one line, «EN – ES», no drop-down; the
// current one marked with aria-current and the rest linked. For the mobile
// menu: David, 06-10-2026, «the language switcher on mobile is ugly, can you
// put EN - ES (without a drop-down)». The order is WPML's. No JS.
if ( 'inline' === ( $attributes['layout'] ?? 'dropdown' ) ) {
	$estrategia_web_text = static fn( array $l ): string => 'name' === ( $attributes['display'] ?? 'code' ) ? $estrategia_web_name( $l ) : strtoupper( (string) $l['code'] );
	$estrategia_web_wrap = get_block_wrapper_attributes(
		array(
			'class'      => 'estrategia-web-language estrategia-web-language--inline',
			'aria-label' => __( 'Language', 'estrategia-web' ),
		)
	);
	echo '<nav ' . $estrategia_web_wrap . '><ul class="estrategia-web-language__inline">'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by core.
	foreach ( $estrategia_web_languages as $estrategia_web_language ) {
		$estrategia_web_code = (string) $estrategia_web_language['code'];
		$estrategia_web_full = $estrategia_web_name( $estrategia_web_language );
		if ( ! empty( $estrategia_web_language['active'] ) ) {
			printf( '<li><span aria-current="true" lang="%1$s" title="%2$s">%3$s</span></li>', esc_attr( $estrategia_web_code ), esc_attr( $estrategia_web_full ), esc_html( $estrategia_web_text( $estrategia_web_language ) ) );
		} else {
			printf( '<li><a href="%1$s" hreflang="%2$s" lang="%2$s" aria-label="%3$s">%4$s</a></li>', esc_url( (string) $estrategia_web_language['url'] ), esc_attr( $estrategia_web_code ), esc_attr( $estrategia_web_full ), esc_html( $estrategia_web_text( $estrategia_web_language ) ) );
		}
	}
	echo '</ul></nav>';
	return;
}
$estrategia_web_label   = 'name' === ( $attributes['display'] ?? 'code' ) ? $estrategia_web_name( $estrategia_web_current ) : strtoupper( (string) $estrategia_web_current['code'] );
$estrategia_web_list_id = wp_unique_id( 'estrategia-web-language-' );
$estrategia_web_wrapper = get_block_wrapper_attributes(
	array(
		'class'               => 'estrategia-web-language',
		'data-wp-interactive' => 'estrategia-web/language-switcher',
		'data-wp-context'     => wp_json_encode( array( 'open' => false ) ),
		'data-wp-on-document--click' => 'callbacks.closeOutside',
		'data-wp-on--keydown' => 'callbacks.closeOnEscape',
	)
);
?>
<div <?php echo $estrategia_web_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by core. ?>>
	<button type="button" class="estrategia-web-language__toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $estrategia_web_list_id ); ?>" data-wp-on--click="actions.toggle" data-wp-bind--aria-expanded="context.open">
		<span class="screen-reader-text"><?php esc_html_e( 'Language:', 'estrategia-web' ); ?> </span><span lang="<?php echo esc_attr( (string) $estrategia_web_current['code'] ); ?>"><?php echo esc_html( $estrategia_web_label ); ?></span>
	</button>
	<ul id="<?php echo esc_attr( $estrategia_web_list_id ); ?>" class="estrategia-web-language__list" hidden data-wp-bind--hidden="!context.open">
		<?php foreach ( $estrategia_web_others as $estrategia_web_language ) : ?>
		<li><a href="<?php echo esc_url( (string) $estrategia_web_language['url'] ); ?>" hreflang="<?php echo esc_attr( (string) $estrategia_web_language['code'] ); ?>" lang="<?php echo esc_attr( (string) $estrategia_web_language['code'] ); ?>"><?php echo esc_html( $estrategia_web_name( $estrategia_web_language ) ); ?></a></li>
		<?php endforeach; ?>
	</ul>
</div>
