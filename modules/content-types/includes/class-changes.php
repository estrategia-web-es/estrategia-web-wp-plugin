<?php
/**
 * THE PROTECTIONS when a definition changes from the panel (17-10-2026): the
 * client can touch a type now, so what would break published content is
 * refused, and what changes something visible is said before it happens.
 * Pure PHP (no WordPress but __()), so level 1 tests it with the machine's
 * PHP (tests/php/changes.php).
 *
 * THE RULES, and why each one:
 *   refused  renaming a type that has entries. Its entries keep the old
 *            post_type in wp_posts: the new name would start empty and the old
 *            ones would vanish from the admin. (No entries: allowed, it is a
 *            typo being fixed.)
 *   refused  renaming a taxonomy that has terms — the same — and, for a type
 *            made from a template, changing its name at all (Schema).
 *   warned   a field removed: its values STAY in the database (nothing is
 *            deleted), they just are not shown or edited; adding the field
 *            back with the same name brings them back.
 *   warned   a field whose type changes: stored values are re-sanitized the
 *            next time each entry is saved (a text that is not a number
 *            becomes the default).
 *   warned   a taxonomy removed: its terms stay, unattached to the type.
 *   warned + redirected  the URL (slug) of a type or of a taxonomy changes:
 *            its published URLs move. class-rest.php creates the 301s in the
 *            redirects module (redirect_paths()); with that module off it
 *            says the old URLs will give 404.
 *
 * Deleting a type is in class-rest.php: refused while it has entries (the
 * reason is there).
 *
 * How to maintain:
 * - Who and when: whoever adds something to a definition that published
 *   content depends on (a new key that ends up in a URL, a name stored in
 *   rows). Its rule goes here, with its case in tests/constructor-pure.test.mjs.
 * - Boundary case, with a name: a slug that changes only in its DEFAULT —
 *   the definition had no slug and the name changed, so the default slug
 *   (the name without the prefix) changed too. Compared on the normalized
 *   definition, so it counts as a slug change, as it should.
 * - The trap: compare NORMALIZED definitions (Schema::normalize), never raw:
 *   «"public": true» written or omitted is the same type, and a raw
 *   comparison would warn about nothing.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Changes {

	/**
	 * @param array|null $old    The NORMALIZED definition stored now (null: a new type).
	 * @param array      $new    The NORMALIZED definition about to be stored.
	 * @param array{entries?:int,terms?:array<string,int>} $counts Entries of the OLD type name; terms per OLD taxonomy name.
	 * @return array{errors:string[],warnings:string[],slugs:array<int,array{0:string,1:string,2:string,3:string}>}
	 *   slugs: [kind ('type'|'taxonomy'), name, old slug, new slug, language code or ''] of every URL base that changes.
	 */
	public static function check( ?array $old, array $new, array $counts ): array {
		$errors   = array();
		$warnings = array();
		$slugs    = array();
		if ( null === $old ) {
			return compact( 'errors', 'warnings', 'slugs' );
		}
		$entries = (int) ( $counts['entries'] ?? 0 );
		if ( $old['name'] !== $new['name'] && $entries > 0 ) {
			/* translators: 1: old type name, 2: number of entries */
			$errors[] = sprintf( __( 'The internal name “%1$s” cannot change: it has %2$d entries, which would stay under the old name. Create a new type instead.', 'estrategia-web' ), $old['name'], $entries );
		}

		$old_taxes = self::taxonomies( $old );
		$new_taxes = self::taxonomies( $new );
		foreach ( $old_taxes as $name => $tax ) {
			$terms = (int) ( $counts['terms'][ $name ] ?? 0 );
			if ( ! isset( $new_taxes[ $name ] ) && $terms > 0 ) {
				/* translators: 1: taxonomy name, 2: number of terms */
				$warnings[] = sprintf( __( 'The taxonomy “%1$s” is removed: its %2$d terms stay in the database, no longer attached to this type. Adding it back with the same name brings them back.', 'estrategia-web' ), $name, $terms );
			}
		}
		// A taxonomy «renamed» is one gone and one new at the same position: with terms, that is refused like a type.
		$old_list = array_keys( $old_taxes );
		$new_list = array_keys( $new_taxes );
		foreach ( $old_list as $i => $name ) {
			$other = $new_list[ $i ] ?? null;
			if ( null !== $other && $other !== $name && ! isset( $new_taxes[ $name ] ) && ! isset( $old_taxes[ $other ] ) && (int) ( $counts['terms'][ $name ] ?? 0 ) > 0 ) {
				/* translators: 1: old taxonomy name, 2: new taxonomy name */
				$errors[] = sprintf( __( 'The taxonomy “%1$s” cannot be renamed to “%2$s”: it has terms, which would stay under the old name.', 'estrategia-web' ), $name, $other );
			}
		}

		$old_fields = array_column( $old['fields'], null, 'name' );
		$new_fields = array_column( $new['fields'], null, 'name' );
		foreach ( $old_fields as $name => $field ) {
			if ( ! isset( $new_fields[ $name ] ) ) {
				/* translators: %s: field name */
				$warnings[] = sprintf( __( 'The field “%s” is removed: what the entries have in it stays in the database, hidden. Add a field with the same name to see it again.', 'estrategia-web' ), $name );
			} elseif ( $new_fields[ $name ]['type'] !== $field['type'] ) {
				/* translators: 1: field name, 2: old field type, 3: new field type */
				$warnings[] = sprintf( __( 'The field “%1$s” changes from %2$s to %3$s: each entry’s value is cleaned for the new type the next time it is saved.', 'estrategia-web' ), $name, $field['type'], $new_fields[ $name ]['type'] );
			}
		}

		if ( $old['name'] === $new['name'] ) {
			$slugs = self::slug_changes( 'type', $new['name'], $old['slug'], $new['slug'] );
		}
		foreach ( $new_taxes as $name => $tax ) {
			if ( isset( $old_taxes[ $name ] ) ) {
				$slugs = array_merge( $slugs, self::slug_changes( 'taxonomy', $name, $old_taxes[ $name ]['slug'], $tax['slug'] ) );
			}
		}
		foreach ( $slugs as [ $kind, $name, $from, $to ] ) {
			/* translators: 1: type or taxonomy name, 2: old URL base, 3: new URL base */
			$warnings[] = sprintf( __( 'The URL of “%1$s” changes from /%2$s/ to /%3$s/: its published addresses move.', 'estrategia-web' ), $name, $from, $to );
		}
		return compact( 'errors', 'warnings', 'slugs' );
	}

	/**
	 * The URL bases that change, LANGUAGE BY LANGUAGE (slugs per language,
	 * 17-10-2026): a slug is a string (every language) or locale → slug.
	 * Each language named in either is compared as Registrar and WPML resolve
	 * it (Schema::text: that language, then en, then the first). The fifth
	 * element is the language code ('' for a plain string on both sides).
	 * Boundary case, with a name: «sedes» → {"es": "sedes", "en": "sedes"}
	 * (Lavandería's migration): the same in every language, so NO change.
	 *
	 * @return array<int,array{0:string,1:string,2:string,3:string,4:string}>
	 */
	private static function slug_changes( string $kind, string $name, $old, $new ): array {
		$langs = array_unique( array_merge( is_array( $old ) ? array_keys( $old ) : array(), is_array( $new ) ? array_keys( $new ) : array() ) );
		$out   = array();
		foreach ( $langs ? $langs : array( '' ) as $lang ) {
			$from = Schema::text( $old, (string) $lang );
			$to   = Schema::text( $new, (string) $lang );
			if ( $from !== $to ) {
				$out[] = array( $kind, $name, $from, $to, strtolower( explode( '_', (string) $lang )[0] ) );
			}
		}
		return $out;
	}

	/**
	 * The redirects a URL change needs: every published path that starts with
	 * the old base, moved to the new one. Pure: the caller gives the paths
	 * (relative permalinks, as WordPress builds them NOW, with the old slug).
	 * The «/en/» of a WPML language stays in front; a path whose base is not
	 * the old one (a language with its own translated slug) is not touched.
	 *
	 * @param string[] $paths Relative URLs: /sedes/, /sedes/maioris/, /en/sedes/son-oms/.
	 * @return array<string,string> old path => new path
	 */
	public static function redirect_paths( array $paths, string $from, string $to ): array {
		$out = array();
		if ( '' === $from || $from === $to ) {
			return $out;
		}
		$quoted = preg_quote( trim( $from, '/' ), '#' );
		foreach ( $paths as $path ) {
			// Optional language prefix (2–3 letters, maybe with a region) and then the old base as a whole segment.
			if ( preg_match( '#^(/(?:[a-z]{2,3}(?:-[a-z]{2})?/)?)' . $quoted . '(/.*)?$#i', (string) $path, $m ) ) {
				$out[ $path ] = $m[1] . trim( $to, '/' ) . ( $m[2] ?? '/' );
			}
		}
		return $out;
	}

	/** @return array<string,array> Taxonomies DECLARED by a normalized definition (not the referenced ones), by name, in order. */
	private static function taxonomies( array $def ): array {
		$out = array();
		foreach ( $def['taxonomies'] as $tax ) {
			if ( is_array( $tax ) ) {
				$out[ $tax['name'] ] = $tax;
			}
		}
		return $out;
	}
}
