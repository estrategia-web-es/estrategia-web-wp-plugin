<?php
/**
 * THE LIST OF EACH TYPE'S ENTRIES (2.8.0, Juan, 02-11-2026: «está todo
 * agrupado y es tedioso encontrar uno»): edit.php?post_type=<type> of every
 * type this module registers gets one dropdown per taxonomy of the type
 * («All cuisines / Italian…»), through \EstrategiaWeb\Admin\Admin_List —
 * the same tool the banners list uses. Several dropdowns add up.
 *
 * What it does NOT add, on purpose:
 * - Sorting: the list's columns are WordPress's (title, date, the
 *   taxonomies), and title and date already sort. No field of a type is a
 *   column today; a field becomes sortable the day it is shown as one, and
 *   that needs a key in the definition («column»), which is the constructor's
 *   business, not this file's.
 * - A dropdown per «select» field: a select can classify (a restaurant's
 *   price range) or only say how something looks (a client's «image fit»),
 *   and the definition does not say which. Taxonomies are the way a type
 *   declares «this is how my entries are grouped».
 * - category: WordPress already prints its own dropdown for any type that
 *   has it; a second one would be a duplicate.
 *
 * How to maintain:
 * - Who and when: whoever changes what a type's list shows. The test is
 *   modules/content-types/tests/admin-list.test.mjs (level 2).
 * - Criterion: what helps someone FIND an entry among hundreds (the header
 *   of includes/admin/class-admin-list.php).
 * - Edge case, by name: gment_cuisine, one taxonomy shared by restaurants and
 *   wines (the theme files of the tests). Its dropdown shows on both lists,
 *   without counts (Admin_List says why).
 * - The trap: the types are known at init 5 (Registrar::register()), so this
 *   runs at init 20; called earlier it would find no type and add nothing,
 *   without any error. And a type registered by OTHER code with the same
 *   name is not ours (Registrar::registered() says which ones are).
 */

namespace EstrategiaWeb\ContentTypes;

use EstrategiaWeb\Admin\Admin_List;

defined( 'ABSPATH' ) || exit;

final class List_Tools {

	public static function boot(): void {
		add_action( 'init', array( self::class, 'declare' ), 20 );
	}

	/** One dropdown per taxonomy of each type this module registered (see the header). */
	public static function declare(): void {
		$ours = Registrar::registered();
		foreach ( array_keys( Loader::types() ) as $type ) {
			if ( ! in_array( $type, $ours, true ) ) {
				continue;
			}
			foreach ( get_object_taxonomies( $type, 'objects' ) as $tax ) {
				if ( 'category' !== $tax->name && $tax->show_ui ) {
					Admin_List::taxonomy_filter( $type, $tax->name );
				}
			}
		}
	}
}
