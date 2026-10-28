<?php
/**
 * Called by tests/type-names.test.mjs with the machine's PHP (no WordPress):
 * the prefixed names (Type_Names). Writes JSON; the assertions live in the
 * .mjs. (Until 2.0.1 also the 1.2.0 migrator's content renaming and the
 * alias for old Queries, removed with them.)
 *
 * How to maintain: a new case is a new key in $out and its assert there.
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../includes/class-type-names.php';
require __DIR__ . '/../../includes/class-settings.php';

use EstrategiaWeb\Site\Settings;
use EstrategiaWeb\Site\Type_Names;

$out = array(
	'prefix'        => Type_Names::PREFIX,
	'keys'          => Type_Names::KEYS,
	'rewrite_names' => Settings::REWRITE_NAMES,
	'types'         => Settings::TYPES,
	'renamed_types' => Type_Names::renamed_types(),
	'renamed_taxes' => Type_Names::renamed_taxonomies(),
	'name_client'   => Type_Names::name( 'client' ),
	'name_page'     => Type_Names::name( 'page' ),
	'key_back'      => Type_Names::key( Type_Names::name( 'client_sector' ) ),
	'key_foreign'   => Type_Names::key( 'client' ),
);

echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
