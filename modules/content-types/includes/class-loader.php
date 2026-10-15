<?php
/**
 * WHERE THE DEFINITIONS LIVE, and reading them: every *.json in
 *
 *   1. <active theme>/content-types/          the child theme (stylesheet dir)
 *   2. <parent theme>/content-types/          only if it is a different folder
 *   3. any folder added with the filter estrategia_web_content_types_dirs
 *      (a site plugin or mu-plugin: `add_filter( 'estrategia_web_content_types_dirs',
 *      fn( $dirs ) => [ ...$dirs, __DIR__ . '/content-types' ] );`)
 *
 * Why the theme by default: it is the part of each site that already lives in
 * the site's repo and is deployed as code. The catch, said out loud: a type
 * whose file lives in the theme is NOT registered if the theme changes. The
 * content stays in the database (nothing is deleted) but disappears from the
 * admin and its URLs give 404 until the new theme carries the folder. A site
 * that may change themes keeps its definitions in a site plugin via the filter.
 *
 * Files are read once per request (static cache). A file that is not valid
 * JSON, or fails Schema::validate() or Schema::check_all(), is NOT registered
 * at all — never half — and its errors are listed in
 * «Estrategia Web → Content types».
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Loader {

	/** @var array{types:array<string,array>,errors:array<string,string[]>,files:array<string,string>}|null */
	private static ?array $cache = null;

	/** @return string[] The folders that are searched, in order (existing or not). */
	public static function dirs(): array {
		$dirs = array( get_stylesheet_directory() . '/content-types' );
		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$dirs[] = get_template_directory() . '/content-types';
		}
		/**
		 * Folders with content type definitions (*.json). Add the site plugin's.
		 *
		 * @param string[] $dirs Absolute paths.
		 */
		$dirs = (array) apply_filters( 'estrategia_web_content_types_dirs', $dirs );
		return array_values( array_unique( array_map( static fn( $d ) => rtrim( wp_normalize_path( (string) $d ), '/' ), $dirs ) ) );
	}

	/**
	 * @return array{types:array<string,array>,errors:array<string,string[]>,files:array<string,string>}
	 *   types: type name => NORMALIZED definition (only valid files);
	 *   errors: file => its errors; files: type name => file.
	 */
	public static function load(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$valid  = array();
		$errors = array();
		foreach ( self::dirs() as $dir ) {
			foreach ( is_dir( $dir ) ? (array) glob( $dir . '/*.json' ) : array() as $file ) {
				$file = wp_normalize_path( $file );
				$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file of the site.
				// A BOM breaks json_decode (CLAUDE.md 3.2 bis): an editor on Windows may add one.
				$def = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $json ), true );
				if ( null === $def && JSON_ERROR_NONE !== json_last_error() ) {
					/* translators: %s: the JSON parser's message */
					$errors[ $file ] = array( sprintf( __( 'Not valid JSON: %s.', 'estrategia-web' ), json_last_error_msg() ) );
					continue;
				}
				$found = Schema::validate( $def );
				if ( $found ) {
					$errors[ $file ] = $found;
					continue;
				}
				$valid[ $file ] = $def;
			}
		}
		foreach ( Schema::check_all( $valid ) as $file => $found ) {
			$errors[ $file ] = $found;
			unset( $valid[ $file ] );
		}
		$types = array();
		$files = array();
		foreach ( $valid as $file => $def ) {
			$types[ $def['name'] ] = Schema::normalize( $def );
			$files[ $def['name'] ] = $file;
		}
		return self::$cache = array( 'types' => $types, 'errors' => $errors, 'files' => $files );
	}

	/** @return array<string,array> Type name => normalized definition. */
	public static function types(): array {
		return self::load()['types'];
	}

	/** The normalized definition of one type, or null. */
	public static function type( string $name ): ?array {
		return self::types()[ $name ] ?? null;
	}

	/** Adds an error found later (at registration) to the screen's list. */
	public static function add_error( string $file, string $error ): void {
		self::load();
		self::$cache['errors'][ $file ][] = $error;
	}

	/** Forget what was read (tests, and after the theme switches). */
	public static function forget(): void {
		self::$cache = null;
	}
}
