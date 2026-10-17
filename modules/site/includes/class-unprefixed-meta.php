<?php
/**
 * The three UNPREFIXED meta keys from before 1.0.0, as READ ALIASES of their
 * prefixed names (unreleased; until 2.0.0):
 *
 *   type          old key              current key
 *   client        client_url           estrategia_web_client_url
 *   testimonial   testimonial_role     estrategia_web_testimonial_role
 *   testimonial   testimonial_client   estrategia_web_testimonial_client
 *
 * Why (David, 17-10-2026: «los prefijos son obligatorios para WordPress»):
 * WordPress.org requires a prefix, and another plugin may well use
 * «client_url». The rest of the metas were already estrategia_web_…; the
 * types got theirs in 1.2.0 (Type_Names). THE MAP LIVES ONLY HERE (MAP):
 * Content_Types registers the new names, and the migrator
 * (includes/class-meta-prefix-migration.php) renames the rows.
 *
 * What the alias covers, and why each thing:
 * - get_post_meta( $id, 'client_url' ) from a theme's PHP, on a client or a
 *   testimonial: it answers with the NEW key's value (get_post_metadata).
 * - And the other way round, until the site is migrated: the NEW key of a
 *   post that still only has the OLD row answers with the old value. That
 *   covers the seconds between uploading the plugin and running the migrator
 *   (or a site where nobody has run it yet).
 * - Block Bindings core/post-meta saved with "key":"client_url": at render
 *   time (render_block_data) the key is swapped for the new one. It has to be
 *   there: core only resolves a key that is REGISTERED with show_in_rest, and
 *   the old one no longer is, so get_post_meta() is never reached.
 *
 * What it does NOT cover, on purpose:
 * - WRITING with the old key (update_post_meta, REST «meta»: {"client_url"…}):
 *   it writes a row with the old name that nobody registers. Our load scripts
 *   already use the new names; an old one gets its field ignored by REST.
 * - The EDITOR: an old binding opened in the editor shows the key as is
 *   (unregistered). The migrator rewrites the content; this only covers
 *   markup it did not see (a restored revision, a pasted pattern).
 * - Posts of OTHER types: the alias only acts on our client and testimonial
 *   (old or new type name). A «client_url» on a page belongs to someone else.
 *
 * How to maintain:
 * - Who: whoever touches the plugin. WHEN IT GOES: in 2.0.0, with the
 *   migrator, Unprefixed_Names and Legacy_Names, once every site has run it
 *   (17-10-2026: only Lavandería Mallorca, which had no rows with these keys).
 * - A new meta does NOT go in here: it is born prefixed (estrategia_web_…).
 * - Edge case, by name: a post with BOTH rows (old and new). The new one wins
 *   everywhere (alias and migrator); the migrator leaves the old one and
 *   warns.
 * - The trap: the alias cannot call get_post_meta() for the other key (it
 *   would go through this same filter, and the old key asks for the new one
 *   which asks for the old one…). It reads the meta CACHE, like core does
 *   right after the filter (get_metadata_raw).
 * - And the other one: the migrator also carries this file over stdin to the
 *   server (Lavandería, despliegue/prefijo-tipos.mjs), where an older plugin
 *   may be running. It cannot use anything that is not in 1.4.3.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Unprefixed_Meta {

	/** Old key → current key. */
	public const MAP = array(
		'client_url'         => 'estrategia_web_client_url',
		'testimonial_role'   => 'estrategia_web_testimonial_role',
		'testimonial_client' => 'estrategia_web_testimonial_client',
	);

	/** Old key → the type KEY it belongs to (Type_Names). */
	public const TYPES = array(
		'client_url'         => 'client',
		'testimonial_role'   => 'testimonial',
		'testimonial_client' => 'testimonial',
	);

	public static function boot(): void {
		add_filter( 'get_post_metadata', array( self::class, 'read' ), 10, 4 );
		add_filter( 'render_block_data', array( self::class, 'block_data' ) );
	}

	/** Old key → its type's names: the registered one («eweb_client») and the pre-1.2.0 one («client»). */
	public static function post_types( string $old ): array {
		$key = self::TYPES[ $old ] ?? '';
		return '' === $key ? array() : array( Type_Names::name( $key ), $key );
	}

	/**
	 * get_post_metadata: the old key reads the new one; the new one, the old
	 * row while the site is not migrated. Null = let core carry on.
	 *
	 * @param mixed  $value     Null unless someone answered earlier.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Requested key ('' = all of them: not touched).
	 * @param bool   $single    Single value.
	 * @return mixed
	 */
	public static function read( $value, $object_id, $meta_key, $single ) {
		if ( null !== $value || ! is_string( $meta_key ) || '' === $meta_key ) {
			return $value;
		}
		$old = array_search( $meta_key, self::MAP, true );
		if ( isset( self::MAP[ $meta_key ] ) ) {
			$old   = $meta_key;
			$other = self::MAP[ $meta_key ]; // Old key requested: the new one answers.
		} elseif ( false !== $old ) {
			$other = $old; // New key requested: the old row, only if there is no new one.
		} else {
			return $value;
		}
		if ( ! in_array( get_post_type( (int) $object_id ), self::post_types( $old ), true ) ) {
			return $value;
		}
		$cache = wp_cache_get( (int) $object_id, 'post_meta' );
		if ( ! is_array( $cache ) ) {
			$all   = update_meta_cache( 'post', array( (int) $object_id ) );
			$cache = $all[ (int) $object_id ] ?? array();
		}
		if ( $meta_key !== $old && isset( $cache[ $meta_key ] ) ) {
			return $value; // The new row exists: it is the one that counts.
		}
		if ( ! isset( $cache[ $other ] ) ) {
			return $value;
		}
		$values = array_map( 'maybe_unserialize', $cache[ $other ] );
		return $single ? array( $values[0] ) : $values;
	}

	/**
	 * render_block_data: core/post-meta bindings with an old key, with the new one.
	 *
	 * @param array $block Parsed block.
	 */
	public static function block_data( $block ): array {
		$block = (array) $block;
		if ( isset( $block['attrs']['metadata']['bindings'] ) && is_array( $block['attrs']['metadata']['bindings'] ) ) {
			$block['attrs']['metadata']['bindings'] = self::bindings( $block['attrs']['metadata']['bindings'] );
		}
		return $block;
	}

	/** A block's «bindings» attribute with the new keys (only core/post-meta). */
	public static function bindings( array $bindings ): array {
		foreach ( $bindings as $attr => $binding ) {
			if ( is_array( $binding ) && 'core/post-meta' === ( $binding['source'] ?? '' ) && isset( $binding['args']['key'] ) && is_string( $binding['args']['key'] ) && isset( self::MAP[ $binding['args']['key'] ] ) ) {
				$bindings[ $attr ]['args']['key'] = self::MAP[ $binding['args']['key'] ];
			}
		}
		return $bindings;
	}
}
