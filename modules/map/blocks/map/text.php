<?php
/**
 * The map block's text fields (place name, address, phone, hours) as plain
 * text with real line breaks, from what an attribute or a Block Bindings source
 * brings: the estrategia-web-site sources send «<br>» for line breaks.
 *
 * How to maintain: render.php escapes the result ONCE; nothing else here may
 * escape. Edge case, with a name: the location source of 2.0.0 derives the
 * address as «line<br>\nline»; the «\n» after the «<br>» must go with it, or
 * every break doubles (blank lines in the address box and «, ,» in the static
 * map's alt — seen on Lavandería's migrated data, 28-10-2026). Test:
 * modules/map/tests/text.test.mjs (level 1, machine PHP). The trap:
 * wp_strip_all_tags() and html_entity_decode() exist only inside WordPress;
 * the test defines stand-ins with the same behaviour for these inputs.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'estrategia_web_map_text' ) ) {
	function estrategia_web_map_text( string $raw ): string {
		return trim( html_entity_decode( wp_strip_all_tags( preg_replace( '/<br\s*\/?>\r?\n?/i', "\n", $raw ) ), ENT_QUOTES, 'UTF-8' ) );
	}
}
