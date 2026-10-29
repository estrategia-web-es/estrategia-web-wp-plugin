<?php
namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * THE FORM AS AN ENTITY (2.0.0): the post type «eweb_form» («Formulario»).
 *
 * Up to 1.7.0 a form was a block tree (estrategia-web-forms/form with its
 * fields and notifications) INSIDE a page or a synced pattern, so editing it
 * meant finding where it lived (sometimes the page, sometimes the pattern)
 * and a copied form duplicated its notifications. Since 2.0.0 a form is a
 * post of its own, like Formello's (David's reference, 27-10-2026), and
 * pages show it with the «Formulario» reference block
 * (estrategia-web-forms/form-ref, Blocks::render_form_ref()).
 *
 * What a form post is:
 * - Its CONTENT is exactly the block tree a page used to carry: ONE
 *   estrategia-web-forms/form block (the template puts it, locked: it cannot
 *   be moved or removed) with the fields, the button and the notifications
 *   inside. The form-level settings (name in the log, identifier, success
 *   and error messages) stay where they were, in that block's side panel:
 *   the same attributes, the same Definitions, the same snapshot and hash.
 *   Nothing about submitting changed.
 * - Its TITLE is only the admin name (the list, the reference block's
 *   picker). The log name and the default email subject are still the
 *   block's «title».
 * - Not public: no URL, no archive, no search. Edited with the block editor
 *   (show_in_rest), listed in «Forms → Forms» (show_in_menu false: that list
 *   is the menu; class-forms-list.php).
 * - With WPML it is translatable like a page (wpml-config.xml,
 *   <custom-types>): each language has its form post, and its texts are
 *   translated with the same <gutenberg-blocks> keys as before. The
 *   reference block renders the current language's one.
 *
 * Who can do what: the type's capabilities are a page's (capability_type
 * «page»): whoever edits pages edits forms, as before (the form was in the
 * page). The REST routes of the type (/wp/v2/eweb-forms) answer only to
 * someone who can edit pages: a published form is readable by REST to
 * anyone otherwise, and although the notifications render nothing, the
 * list of forms of a site is nobody's business (rest_guard()).
 *
 * How to maintain:
 * - Who: whoever touches the forms model. A form-level setting goes into the
 *   form block (block.json + editor.js + Definitions), not into post meta:
 *   that way it travels in the snapshot and WPML translates it with the
 *   block.
 * - Edge case, by name: a form inside a form (a reference block in a form
 *   post). The editor does not offer it (allowed_blocks()) and the render
 *   stops a loop anyway (Blocks::render_form_ref()).
 * - Edge case: a form post with no form block (someone used the code
 *   editor). The reference renders whatever the post has; Forms → Forms
 *   shows it with no notifications. The template comes back on a new post
 *   only.
 * - The trap: show_in_menu false still leaves edit.php?post_type=eweb_form
 *   working (WordPress sends there after «Move to trash»); it is WordPress's
 *   plain list and nothing links to it on purpose. And the menu highlight on
 *   the editing screen is set by hand (highlight()).
 * - Tests: modules/forms/tests/form-ref.test.mjs (level 2).
 */

final class Form_Post_Type {

	public const TYPE      = 'eweb_form';
	public const REST_BASE = 'eweb-forms';

	/** The blocks of the plugin's forms, by name. */
	public const FORM_BLOCK = 'estrategia-web-forms/form';
	public const REF_BLOCK  = 'estrategia-web-forms/form-ref';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ), 9 ); // Before Blocks::register (10): the template names the form block.
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest_guard' ), 10, 3 );
		add_filter( 'allowed_block_types_all', array( self::class, 'allowed_blocks' ), 10, 2 );
		add_filter( 'parent_file', array( self::class, 'highlight' ) );
		add_filter( 'submenu_file', array( self::class, 'highlight_submenu' ) );
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Forms', 'estrategia-web' ),
					'singular_name'      => __( 'Form', 'estrategia-web' ),
					'add_new'            => __( 'Add form', 'estrategia-web' ),
					'add_new_item'       => __( 'Add form', 'estrategia-web' ),
					'edit_item'          => __( 'Edit form', 'estrategia-web' ),
					'new_item'           => __( 'New form', 'estrategia-web' ),
					'search_items'       => __( 'Search forms', 'estrategia-web' ),
					'not_found'          => __( 'No forms found.', 'estrategia-web' ),
					'not_found_in_trash' => __( 'No forms found in Trash.', 'estrategia-web' ),
					'item_published'     => __( 'Form published.', 'estrategia-web' ),
					'item_updated'       => __( 'Form updated.', 'estrategia-web' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => true,
				'rest_base'           => self::REST_BASE,
				'query_var'           => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'template'            => array(
					array( self::FORM_BLOCK, array( 'lock' => array( 'move' => true, 'remove' => true ) ) ),
				),
			)
		);
	}

	/**
	 * The id of the form post to render for a reference: the translation in
	 * the current language (WPML; the original if there is none) and only
	 * if it is a published form. 0 otherwise.
	 */
	public static function resolve( int $ref ): int {
		if ( $ref <= 0 ) {
			return 0;
		}
		$id   = Language::object_id( $ref, self::TYPE, Language::current() );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || self::TYPE !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return 0;
		}
		return (int) $post->ID;
	}

	/** /wp/v2/eweb-forms… only for whoever can edit pages (see the header). */
	public static function rest_guard( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( ! preg_match( '#^/wp/v2/' . preg_quote( self::REST_BASE, '#' ) . '(/|$)#', (string) $request->get_route() ) ) {
			return $result;
		}
		if ( current_user_can( 'edit_pages' ) ) {
			return $result;
		}
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'estrategia-web' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * In a form post's editor, everything but the reference block (a form
	 * inside a form). Elsewhere, untouched.
	 *
	 * @param bool|string[] $allowed
	 * @return bool|string[]
	 */
	public static function allowed_blocks( $allowed, $context ) {
		$post = is_object( $context ) && isset( $context->post ) ? $context->post : null;
		if ( ! $post instanceof \WP_Post || self::TYPE !== $post->post_type ) {
			return $allowed;
		}
		$names = true === $allowed || ! is_array( $allowed ) ? array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) : $allowed;
		return array_values( array_diff( $names, array( self::REF_BLOCK ) ) );
	}

	/** The «Forms» menu stays open while a form post is edited. */
	public static function highlight( $parent_file ) {
		global $typenow;
		return self::TYPE === $typenow ? Admin::PAGE : $parent_file;
	}

	public static function highlight_submenu( $submenu_file ) {
		global $typenow;
		return self::TYPE === $typenow ? Forms_List::PAGE : $submenu_file;
	}
}
