<?php
/**
 * Runs Plugin Check inside the Playground of tools/wporg.mjs and writes the
 * result to /out/result.json. Not part of the plugin (tools/ is export-ignore).
 *
 * Why not `wp plugin check`: Playground's CLI does not show the stdout of a
 * blueprint's wp-cli step, so the result was lost. This calls the same
 * runner class Plugin Check's CLI uses, without WP-CLI.
 *
 * Static checks only: the runtime ones create demo posts first, and that
 * insert fails in Playground («Could not insert post into the database»).
 * WordPress.org's upload scan is the static one.
 *
 * How to maintain: if a Plugin Check update renames Abstract_Check_Runner or
 * its abstract methods, result.json comes back with «exception»: adapt the
 * anonymous class below to the new ones (plugin-check/includes/Checker/).
 */

require_once '/wordpress/wp-load.php';

use WordPress\Plugin_Check\Checker\Abstract_Check_Runner;

$estrategia_web_runner = new class() extends Abstract_Check_Runner {
	public static function is_plugin_check() {
		return true;
	}
	protected function get_plugin_param() {
		return 'estrategia-web';
	}
	protected function get_check_slugs_param() {
		return array();
	}
	protected function get_check_exclude_slugs_param() {
		return array();
	}
	protected function get_include_experimental_param() {
		return true;
	}
	protected function get_categories_param() {
		return array();
	}
	protected function get_slug_param() {
		return 'estrategia-web';
	}
	protected function get_mode_param() {
		return 'new';
	}
	protected function allow_runtime_checks(): bool {
		return false;
	}
};

try {
	$estrategia_web_runner->set_plugin( 'estrategia-web' );
	$estrategia_web_runner->set_experimental_flag( true );
	$estrategia_web_runner->prepare();
	$estrategia_web_result = $estrategia_web_runner->run();
	$estrategia_web_out    = array(
		'errors'   => $estrategia_web_result->get_errors(),
		'warnings' => $estrategia_web_result->get_warnings(),
	);
} catch ( Throwable $e ) {
	$estrategia_web_out = array( 'exception' => get_class( $e ) . ': ' . $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine() );
}
file_put_contents( '/out/result.json', json_encode( $estrategia_web_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
