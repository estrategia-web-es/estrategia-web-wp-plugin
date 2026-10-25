<?php
/**
 * TESTS ONLY. Real WPML in the plugin's test WordPress, when tests/run.mjs
 * has mounted a local copy (it is a paid plugin: never in git; where the copy
 * comes from, in the header of tests/run.mjs).
 *
 * tests/blueprint.json runs this file TWICE, in two requests, because WPML
 * boots on plugins_loaded and in the request that activates it that hook has
 * already passed:
 *   1st run: WPML is mounted but not loaded → activates WPML and WPML String
 *            Translation, and stops there.
 *   2nd run: WPML is loaded → leaves it as a FINISHED install: Spanish by
 *            default, Spanish and English active, language by directory
 *            (/en/), and the two marks of a completed wizard.
 * Without the copy mounted, both runs say «no WPML» and do nothing.
 *
 * THE TWO MARKS, and why both (24-10-2026, Lavandería production):
 * setup_complete is what finish_installation() sets, and without it WPML is
 * installed but filters nothing. existing_content_language_verified is the
 * mark of STEP 1 of the wizard, which finish_installation() does NOT touch,
 * and without it WPML does not hook its block of public filters
 * (sitepress.class.php: the translated front page, the language in
 * pre_get_posts…): /en/ redirected to /en/home/ in production for days.
 * This WordPress sets both, so a plugin test never sees the half-installed
 * state. (Lavandería's test WordPress leaves the wizard unfinished on
 * purpose: there the content loader's «activar» step is what is under test.)
 *
 * How to maintain: if a site of the family uses another default language or
 * URL mode, it is NOT changed here; this is the plugin's reference setup and
 * every WPML test of the plugin assumes es + en in /en/. The trap:
 * WPML_Installation is internal to WPML; if a new version changes it, this
 * fails when Playground boots (the blueprint shows the error), not silently:
 * the last line says what was left.
 *
 * @package estrategia-web
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_dir( WP_PLUGIN_DIR . '/sitepress-multilingual-cms' ) ) {
	echo "ew-setup-wpml: no WPML mounted\n";
	return;
}

if ( ! class_exists( 'SitePress' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$ew_result = activate_plugins( array( 'sitepress-multilingual-cms/sitepress.php', 'wpml-string-translation/plugin.php' ) );
	echo 'ew-setup-wpml: activated (' . ( is_wp_error( $ew_result ) ? $ew_result->get_error_message() : 'ok' ) . "); configured in the next request\n";
	return;
}

global $sitepress, $wpdb;
wp_set_current_user( 1 );

$ew_install = new WPML_Installation( $wpdb, $sitepress );
$ew_install->finish_step1( 'es' );
$ew_install->set_active_languages( array( 'es', 'en' ) );
$sitepress->set_setting( 'language_negotiation_type', 1 );
$sitepress->set_setting( 'urls', array( 'directory_for_default_language' => 0, 'show_on_root' => '', 'root_html_file_path' => '', 'root_page' => 0, 'hide_language_switchers' => 1 ) );
$sitepress->set_setting( 'existing_content_language_verified', 1 );
$sitepress->save_settings();
if ( ! $sitepress->get_setting( 'setup_complete' ) ) {
	$ew_install->finish_installation();
}

echo 'ew-setup-wpml: languages=' . implode( ',', array_keys( $sitepress->get_active_languages( true ) ) )
	. ' default=' . $sitepress->get_default_language()
	. ' setup_complete=' . ( $sitepress->get_setting( 'setup_complete' ) ? 1 : 0 )
	. ' existing_content_language_verified=' . (int) $sitepress->get_setting( 'existing_content_language_verified' ) . "\n";
