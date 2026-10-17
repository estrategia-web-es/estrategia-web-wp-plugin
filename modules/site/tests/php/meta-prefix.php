<?php
/**
 * Called by modules/site/tests/meta-prefix.test.mjs with the machine's PHP
 * (no WordPress): the meta map (Unprefixed_Meta::MAP), the read alias
 * (Unprefixed_Meta::read, over a fake meta cache), the bindings alias and the
 * migrator's content renaming (Meta_Prefix_Migration::rename_markup). Writes
 * JSON; the assertions live in the .mjs.
 *
 * How to maintain: a new case is a new key in $out and its assert there. The
 * fake posts are in $GLOBALS['posts'] (id => type) and their metas in
 * $GLOBALS['cache'] (id => key => [values]), the shape of WordPress's
 * post_meta cache.
 */

define( 'ABSPATH', __DIR__ . '/' );

function add_filter(): void {}
function maybe_unserialize( $v ) {
	return is_string( $v ) && str_starts_with( $v, 'a:' ) ? unserialize( $v ) : $v; // phpcs:ignore
}
function get_post_type( $id ) {
	return $GLOBALS['posts'][ (int) $id ] ?? false;
}
function wp_cache_get( $id, $group ) {
	return 'post_meta' === $group ? ( $GLOBALS['cache'][ (int) $id ] ?? false ) : false;
}
function update_meta_cache( $type, $ids ) {
	$out = array();
	foreach ( $ids as $id ) {
		$out[ $id ] = $GLOBALS['cache'][ $id ] ?? array();
	}
	return $out;
}

require __DIR__ . '/../../includes/class-type-names.php';
require __DIR__ . '/../../includes/class-unprefixed-meta.php';
require __DIR__ . '/../../../../includes/class-meta-prefix-migration.php';

use EstrategiaWeb\Site\Meta_Prefix_Migration;
use EstrategiaWeb\Site\Type_Names;
use EstrategiaWeb\Site\Unprefixed_Meta;

$GLOBALS['posts'] = array(
	1 => Type_Names::name( 'client' ),
	2 => Type_Names::name( 'client' ),
	3 => Type_Names::name( 'client' ),
	4 => 'page',
	5 => Type_Names::name( 'testimonial' ),
	6 => 'client', // Pre-1.2.0 type name: a site that jumps from 1.1.0.
);
$GLOBALS['cache'] = array(
	1 => array( 'estrategia_web_client_url' => array( 'https://new.test' ) ),
	2 => array( 'client_url' => array( 'https://old.test' ) ),
	3 => array( 'client_url' => array( 'https://old.test' ), 'estrategia_web_client_url' => array( 'https://new.test' ) ),
	4 => array( 'client_url' => array( 'https://someone-else.test' ) ),
	5 => array( 'estrategia_web_testimonial_client' => array( '7' ), 'testimonial_role' => array( 'Gerente' ) ),
	6 => array( 'estrategia_web_client_url' => array( 'https://six.test' ) ),
);

$read = static fn( int $id, string $key, bool $single = true ) => Unprefixed_Meta::read( null, $id, $key, $single );

$source_first = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"client_url"}}}}} --><p></p><!-- /wp:paragraph -->';
$args_first   = '<!-- wp:button {"metadata":{"bindings":{"url":{"args":{"key":"client_url"},"source":"core/post-meta"},"text":{"source":"core/post-meta","args":{"key":"testimonial_role"}}}}} /-->';
$others       = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"client_url"}}}}} --><p>"key":"client_url" core/post-meta</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"estrategia_web_client_url"}}}}} --><p></p><!-- /wp:paragraph -->';

$once  = Meta_Prefix_Migration::rename_markup( $source_first . $args_first, $n1 );
$twice = Meta_Prefix_Migration::rename_markup( $once, $n2 );

$out = array(
	'map'                => Unprefixed_Meta::MAP,
	'types'              => Unprefixed_Meta::TYPES,
	'post_types'         => Unprefixed_Meta::post_types( 'testimonial_role' ),
	'old_reads_new'      => $read( 1, 'client_url' ),
	'old_reads_new_all'  => $read( 1, 'client_url', false ),
	'new_reads_old'      => $read( 2, 'estrategia_web_client_url' ),
	'old_when_old'       => $read( 2, 'client_url' ),
	'both_old'           => $read( 3, 'client_url' ),
	'both_new'           => $read( 3, 'estrategia_web_client_url' ),
	'page_old'           => $read( 4, 'client_url' ),
	'page_new'           => $read( 4, 'estrategia_web_client_url' ),
	'testimonial_client' => $read( 5, 'testimonial_client' ),
	'testimonial_role'   => $read( 5, 'estrategia_web_testimonial_role' ),
	'unrelated'          => $read( 5, 'estrategia_web_testimonial_rating' ),
	'all_keys'           => $read( 5, '' ),
	'old_type_name'      => $read( 6, 'client_url' ),
	'answered_before'    => Unprefixed_Meta::read( 'x', 1, 'client_url', true ),
	'bindings'           => Unprefixed_Meta::bindings(
		array(
			'content' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'client_url' ) ),
			'url'     => array( 'source' => 'estrategia-web-site/company', 'args' => array( 'key' => 'client_url' ) ),
			'text'    => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'estrategia_web_testimonial_author' ) ),
		)
	),
	'block_data'         => Unprefixed_Meta::block_data( array( 'blockName' => 'core/paragraph', 'attrs' => array( 'metadata' => array( 'bindings' => array( 'content' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'testimonial_client' ) ) ) ) ) ) ),
	'block_plain'        => Unprefixed_Meta::block_data( array( 'blockName' => 'core/paragraph', 'attrs' => array() ) ),
	'markup_once'        => $once,
	'markup_count'       => $n1,
	'markup_twice'       => $twice,
	'markup_count2'      => $n2,
	'markup_others'      => Meta_Prefix_Migration::rename_markup( $others, $n3 ),
	'markup_others_n'    => $n3,
	'others_in'          => $others,
);

echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
