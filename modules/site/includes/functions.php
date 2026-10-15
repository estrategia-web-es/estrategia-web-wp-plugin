<?php
/**
 * Global functions of the site module, for THEMES (no namespace, with the
 * estrategia_web_ prefix). Contract: README, «Nombres públicos».
 *
 * How to maintain: only what a theme needs to call from a pattern or a PHP
 * template goes in here; the logic stays in its classes. A theme that uses
 * them guards them with function_exists(): without the plugin, the pattern
 * must still load (empty, but without a fatal error).
 */

defined( 'ABSPATH' ) || exit;

/**
 * The REGISTERED name of a plugin type or taxonomy from its key (1.2.0):
 * estrategia_web_type_name( 'client' ) → 'eweb_client'. For the "postType" of
 * a Query in a pattern:
 *
 *     "postType":"<?php echo esc_attr( function_exists( 'estrategia_web_type_name' ) ? estrategia_web_type_name( 'client' ) : 'eweb_client' ); ?>"
 *
 * A key that does not belong to the plugin («page») comes out unchanged.
 */
function estrategia_web_type_name( string $key ): string {
	return \EstrategiaWeb\Site\Type_Names::name( $key );
}
