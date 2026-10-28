<?php
/**
 * THE REGISTERED NAMES of the content types and their taxonomies: the plugin
 * key («client») with the prefix in front («eweb_client»). Unpublished
 * (1.2.0): up to 1.1.0 they were registered without a prefix, and WordPress.org
 * requires one (another plugin's «client» would clash with ours).
 *
 * TWO PREFIXES, ON PURPOSE: estrategia_web_ / estrategia-web for options,
 * metas, tables, blocks, hooks…, and «eweb_» only for types and taxonomies,
 * because WordPress cuts a type at 20 characters and «estrategia_web_testimonial»
 * is 26. Why «eweb_» (David, 15-10-2026), measured on the code of every
 * WordPress.org plugin (veloria.dev): no plugin uses «eweb_»; «ew_» is used by
 * 13 (Easy Watermark, Essential Widgets…) and «ewp_» by 9 (ElasticPress), so
 * they were ruled out; «estweb_» was not liked.
 *
 *   key                 registered name (= REST route /wp/v2/<name>)
 *   client              eweb_client
 *   client_sector       eweb_client_sector
 *   testimonial         eweb_testimonial
 *   location            eweb_location
 *   project             eweb_project
 *   project_category    eweb_project_category
 *   portfolio           eweb_portfolio
 *   portfolio_category  eweb_portfolio_category
 *
 * THE KEY and THE NAME are two different things, on purpose:
 * - The KEY is the plugin's vocabulary: the estrategia_web_site option
 *   (enabled_types, rewrite_slugs), the estrategia_web_site_rewrite_slug
 *   filter, the labels, the fields panel. It does not change: the option
 *   already lives inside a prefixed name, and renaming its values would mean
 *   migrating the client's settings for nothing.
 * - The NAME is what WordPress (and other plugins) see: wp_posts.post_type,
 *   wp_term_taxonomy.taxonomy, a Query's «postType», the REST route, WPML's
 *   element_type («post_eweb_client»), Rank Math's options
 *   («pt_eweb_client_title»), the templates («single-eweb_client»).
 * Public URLs carry NEITHER of the two: they come from the translated slug
 * (/clientes/), as always.
 *
 * How to maintain:
 * - THE PREFIX LIVES ONLY HERE (PREFIX). Everything else asks for it with
 *   name(): Content_Types, Location and the public function
 *   estrategia_web_type_name() used by the themes' patterns. (The 1.2.0
 *   migrator and the alias for the unprefixed names were removed in 2.0.1;
 *   renamed() stays: the test WordPress's reset uses it.)
 *   Outside the plugin there is ONE copy, and it checks itself:
 *   content/semilla/tipos.mjs of each site (the load stops if /wp/v2/types does
 *   not have the name).
 * - The limit that rules: WordPress cuts a type at 20 characters and a
 *   taxonomy at 32. The longest type is «testimonial» (11), so the prefix
 *   cannot exceed 9, underscore included. The level 1 test checks it
 *   (modules/site/tests/type-names.test.mjs).
 * - A new type or taxonomy: its key in KEYS (and in Settings::TYPES /
 *   REWRITE_NAMES; the same test compares the lists). It is born already
 *   prefixed and does not go into the migrator or the alias: those are for
 *   what existed without it.
 * - CHANGING THE PREFIX on an already published site is a whole new migration
 *   (the 1.2.0 one with another map): it is not touched after 1.2.0 is
 *   published.
 * - Edge case, by name: «location». It is also a common word (HTTP header,
 *   keywords of the map block); that is why nothing is searched by hand for
 *   the word: always by key and name().
 * - The trap: the migrator also carries this file over stdin to the server
 *   (desplegar.mjs), where the old plugin is still running. It cannot use
 *   anything that is not in 1.1.0: only PHP and WordPress.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Type_Names {

	/** THE prefix. Changing it is this line (and content/semilla/tipos.mjs of each site). */
	public const PREFIX = 'eweb_';

	/** The keys: the five types and the three taxonomies (= Settings::REWRITE_NAMES). */
	public const KEYS = array( 'client', 'client_sector', 'testimonial', 'location', 'project', 'project_category', 'portfolio', 'portfolio_category' );

	/** The ones that are taxonomies (the rest, types). */
	public const TAXONOMIES = array( 'client_sector', 'project_category', 'portfolio_category' );

	/** «client» → «eweb_client». A key that is not ours comes out unchanged. */
	public static function name( string $key ): string {
		return in_array( $key, self::KEYS, true ) ? self::PREFIX . $key : $key;
	}

	/** «eweb_client» → «client»; '' if it is not one of ours. */
	public static function key( string $name ): string {
		if ( ! str_starts_with( $name, self::PREFIX ) ) {
			return '';
		}
		$key = substr( $name, strlen( self::PREFIX ) );
		return in_array( $key, self::KEYS, true ) ? $key : '';
	}

	/** @return array<string,string> Pre-1.2.0 name (unprefixed) → current name. */
	public static function renamed(): array {
		$out = array();
		foreach ( self::KEYS as $key ) {
			$out[ $key ] = self::PREFIX . $key;
		}
		return $out;
	}

	/** @return array<string,string> Types only. */
	public static function renamed_types(): array {
		return array_diff_key( self::renamed(), array_flip( self::TAXONOMIES ) );
	}

	/** @return array<string,string> Taxonomies only. */
	public static function renamed_taxonomies(): array {
		return array_intersect_key( self::renamed(), array_flip( self::TAXONOMIES ) );
	}
}
