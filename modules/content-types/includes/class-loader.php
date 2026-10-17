<?php
/**
 * WHERE THE DEFINITIONS LIVE, and reading them. Two sources, in this order:
 *
 *   1. THE DATABASE (17-10-2026, the main one): the definitions created in
 *      «Estrategia Web → Content types», copied from a template or loaded
 *      from the site's repo (class-store.php). Only the ACTIVE ones.
 *   2. JSON FILES, read-only (the first way, 15-10-2026): every *.json in
 *        <active theme>/content-types/   the child theme (stylesheet dir)
 *        <parent theme>/content-types/   only if it is a different folder
 *        any folder added with the filter estrategia_web_content_types_dirs
 *        (`add_filter( 'estrategia_web_content_types_dirs',
 *        fn( $dirs ) => [ ...$dirs, __DIR__ . '/content-types' ] );`)
 *
 * WHY THE FILES STAY, as the second way (decided 17-10-2026): a plugin of
 * someone else (or a site that wants a type the client must NOT touch) can
 * still declare one as code, with nothing to load into each database. They
 * are shown in the panel as «file» and cannot be edited there.
 * THE BOUNDARY CASE, with a name: the same type name in the database AND in
 * a file. The database wins — it is what the person edited last — and the
 * file is listed as overridden (not registered). Importing a theme's JSON
 * into the panel is therefore how a file-type becomes editable: then the
 * file can be deleted from the theme.
 * The catch of the theme folder, said out loud: a type whose file lives in
 * the theme is NOT registered if the theme changes (its content stays, its
 * URLs give 404 until the new theme carries the folder). Definitions in the
 * database do not have that problem.
 *
 * Read once per request (static cache; Store::save() and the theme switch
 * forget it). A definition that is not valid JSON, or fails
 * Schema::validate() (with its template, if it comes from one) or
 * Schema::check_all() — across BOTH sources — is NOT registered at all,
 * never half, and its errors are listed in «Estrategia Web → Content types».
 *
 * A source is named by a string: the file path for a file, and
 * «database:<type name>» for a row of the database (is_database()).
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Loader {

	public const DB_SOURCE = 'database:';

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

	/** Whether a source name is a row of the database (and not a file). */
	public static function is_database( string $source ): bool {
		return str_starts_with( $source, self::DB_SOURCE );
	}

	/**
	 * @return array{types:array<string,array>,errors:array<string,string[]>,files:array<string,string>}
	 *   types: type name => NORMALIZED definition (only valid ones);
	 *   errors: source => its errors; files: type name => its source.
	 */
	public static function load(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$valid  = array();
		$errors = array();
		$taken  = array(); // Type names the database already defines.
		foreach ( Store::all() as $name => $row ) {
			if ( ! $row['active'] ) {
				continue;
			}
			$source = self::DB_SOURCE . $name;
			if ( null === $row['definition'] ) {
				$errors[ $source ] = array( __( 'The stored definition is not valid JSON.', 'estrategia-web' ) );
				continue;
			}
			$taken[ $name ] = true;
			$found          = Schema::validate( $row['definition'], Templates::for_definition( $row['definition'] ) );
			if ( $found ) {
				$errors[ $source ] = $found;
				continue;
			}
			$valid[ $source ] = $row['definition'];
		}
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
				if ( is_array( $def ) && isset( $def['name'] ) && is_string( $def['name'] ) && isset( $taken[ $def['name'] ] ) ) {
					/* translators: %s: post type name */
					$errors[ $file ] = array( sprintf( __( 'The type “%s” is defined in the database (Estrategia Web → Content types), which wins: this file is ignored.', 'estrategia-web' ), $def['name'] ) );
					continue;
				}
				$found = Schema::validate( $def, Templates::for_definition( $def ) );
				if ( $found ) {
					$errors[ $file ] = $found;
					continue;
				}
				$valid[ $file ] = $def;
			}
		}
		foreach ( Schema::check_all( $valid ) as $source => $found ) {
			$errors[ $source ] = $found;
			unset( $valid[ $source ] );
		}
		$types = array();
		$files = array();
		foreach ( $valid as $source => $def ) {
			$types[ $def['name'] ] = Schema::normalize( $def );
			$files[ $def['name'] ]  = $source;
		}
		return self::$cache = array( 'types' => $types, 'errors' => $errors, 'files' => $files, 'raw' => $valid );
	}

	/** @return array<string,array> Source => RAW definition, the valid ones (what Schema::check_all() compares a new one with). */
	public static function raw(): array {
		return self::load()['raw'];
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

	/** Forget what was read (tests, a saved definition, and after the theme switches). */
	public static function forget(): void {
		self::$cache = null;
	}
}
