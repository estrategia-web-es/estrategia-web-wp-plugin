<?php
/**
 * Called by tests/type-names.test.mjs with the machine's PHP (no WordPress):
 * the prefixed names (Type_Names), the migrator's content renaming
 * (Prefix_Migration::rename_markup) and the alias for old Queries
 * (Unprefixed_Names). Writes JSON; the assertions live in the .mjs.
 *
 * How to maintain: a new case is a new key in $out and its assert there.
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../includes/class-type-names.php';
require __DIR__ . '/../../includes/class-settings.php';
require __DIR__ . '/../../includes/class-unprefixed-names.php';
require __DIR__ . '/../../../../includes/class-prefix-migration.php';

use EstrategiaWeb\Site\Prefix_Migration;
use EstrategiaWeb\Site\Settings;
use EstrategiaWeb\Site\Type_Names;
use EstrategiaWeb\Site\Unprefixed_Names;

$map = Type_Names::renamed();

$query_old = '<!-- wp:query {"queryId":61,"query":{"perPage":50,"postType":"client","taxQuery":{"client_sector":[12]},"inherit":false}} -->'
	. "\n<div class=\"wp-block-query\"><p>Our clients: client, location</p></div>\n<!-- /wp:query -->";
$query_include = '<!-- wp:query {"queryId":11,"query":{"postType":"client","taxQuery":{"include":{"client_sector":[3,4]}},"inherit":false}} --><!-- /wp:query -->';
$others        = '<!-- wp:query {"queryId":1,"query":{"postType":"post"}} --><!-- /wp:query -->'
	. '<!-- wp:post-terms {"term":"client_sector"} /-->'
	. '<!-- wp:navigation-link {"label":"Locations","type":"location","id":7,"kind":"post-type"} /-->'
	. '<!-- wp:paragraph --><p>"postType":"client"</p><!-- /wp:paragraph -->';

$once  = Prefix_Migration::rename_markup( $query_old, $map, $n1 );
$twice = Prefix_Migration::rename_markup( $once, $map, $n2 );

$out = array(
	'prefix'          => Type_Names::PREFIX,
	'keys'            => Type_Names::KEYS,
	'rewrite_names'   => Settings::REWRITE_NAMES,
	'types'           => Settings::TYPES,
	'renamed_types'   => Type_Names::renamed_types(),
	'renamed_taxes'   => Type_Names::renamed_taxonomies(),
	'name_client'     => Type_Names::name( 'client' ),
	'name_page'       => Type_Names::name( 'page' ),
	'key_back'        => Type_Names::key( Type_Names::name( 'client_sector' ) ),
	'key_foreign'     => Type_Names::key( 'client' ),
	'markup_once'     => $once,
	'markup_count'    => $n1,
	'markup_twice'    => $twice,
	'markup_count2'   => $n2,
	'markup_include'  => Prefix_Migration::rename_markup( $query_include, $map ),
	'markup_others'   => Prefix_Migration::rename_markup( $others, $map, $n3 ),
	'markup_others_n' => $n3,
	'alias_query'     => Unprefixed_Names::query( array( 'postType' => 'client', 'taxQuery' => array( 'client_sector' => array( 12 ) ) ) ),
	'alias_include'   => Unprefixed_Names::query( array( 'postType' => 'location', 'taxQuery' => array( 'include' => array( 'client_sector' => array( 3 ) ) ) ) ),
	'alias_new'       => Unprefixed_Names::query( array( 'postType' => Type_Names::name( 'client' ) ) ),
	'alias_post'      => Unprefixed_Names::query( array( 'postType' => 'post' ) ),
	'alias_terms'     => Unprefixed_Names::block_data( array( 'blockName' => 'core/post-terms', 'attrs' => array( 'term' => 'client_sector' ) ) ),
);

echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
