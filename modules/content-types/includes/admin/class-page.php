<?php
/**
 * «Estrategia Web → Content types»: THE CONSTRUCTOR (17-10-2026), made into
 * two screens in 1.8.0 with ACF's field groups as the reference (David: the
 * single page — every type and every field open, one after the other — was
 * one endless scroll and could not be used):
 *
 *   admin.php?page=estrategia-web-content-types               THE LIST
 *   admin.php?page=estrategia-web-content-types&type=<name>   EDIT one type
 *   admin.php?page=estrategia-web-content-types&new=1         a new one
 *   …&new=1&import=1                                          a new one, opening the JSON box (import)
 *
 * THE LIST is painted here, in PHP: a WP_List_Table (class-types-table.php)
 * with the row actions Edit, Deactivate / Activate, Export JSON and Delete
 * (or «Copy to this panel» for a type from a file), and, as page-title
 * actions, «Add new», «Add from template» (a button that opens the list of
 * templates, a disclosure: aria-expanded, Escape closes it) and «Import
 * JSON». Below it, the theme files with errors and the folders searched.
 *
 * THE EDIT SCREEN is painted by assets/constructor.js (PHP gives it the
 * frame: the title, «Back to the list», the type's name in data-type): the
 * type's settings in TABS (General: names, prefix, internal name, address;
 * URLs & languages: the address in the other languages and WPML;
 * Taxonomies; Advanced: icon, menu position, supports, public, archive,
 * where the fields are edited, the field groups, and the JSON box), and the
 * FIELDS as collapsed rows (label, name, type) that open on click, reordered
 * by dragging or with Alt + arrow keys / the Move buttons, each move said
 * aloud with wp.a11y.speak().
 *
 * Everything is read and written through the REST route (class-rest.php),
 * which validates — nothing is saved from PHP. Its nonce is the one
 * wp.apiFetch carries (wp_rest), the capability manage_options, the same as
 * before. PHP gives JS the route, the list's URL and its texts already
 * translated (strings()), so the translations live in the .po like any PHP
 * text.
 *
 * OLD URLS: the single page had only ?page=estrategia-web-content-types,
 * which is still the list. What can be stale is a link to one type: a
 * ?type= of a name that no longer exists (deleted, renamed) redirects to the
 * list with a notice that says so (load()), never an empty editor.
 *
 * ACCESSIBLE ON PURPOSE: every control has its <label>, the tabs are a
 * tablist (arrow keys, Home / End), each field row's toggle has
 * aria-expanded and moves the focus into what it opens, errors and
 * confirmations go to a region that takes the focus (role="alert"),
 * progress and moves to a polite live region, and nothing needs a mouse.
 * tests/constructor-editor.test.mjs drives it with the keyboard-reachable
 * controls only.
 *
 * How to maintain:
 * - Who and when: whoever adds a key to the schema that a person should set
 *   from the panel: its control in constructor.js (in the tab where a person
 *   would look for it: a type's key in typeTabs(), a field's in fieldBody()),
 *   its text in strings(). A key the form does not show is KEPT as it is
 *   (the form edits a copy of the raw definition), and the JSON box of the
 *   Advanced tab edits anything.
 * - A text of the LIST is PHP's (this file, class-types-table.php); a text
 *   JS paints goes in strings(). The same msgid can be in both.
 * - Boundary case, with a name: a type made from a TEMPLATE. Its internal
 *   name and the fields the template ships cannot be renamed (their stored
 *   data would be orphaned); everything else can be edited. And a type from
 *   a FILE: its edit screen is «copy to this panel» — saving creates the
 *   database copy, which wins from then on.
 * - The trap: a text with a placeholder is used in JS with fill() (%s, %1$s,
 *   %2$s): keep the translators: comment here, the .po is where it is read.
 *   And the redirect of a stale ?type= has to run on load-<hook>, before the
 *   admin header is printed: in render() it is too late (headers sent).
 * - Tests: constructor-model.test.mjs (level 1, the pure part),
 *   constructor-screens.test.mjs (level 2: list → edit → save, the stale
 *   URL), constructor-editor.test.mjs (level 3: the tabs, the rows, drag
 *   and keyboard).
 */

namespace EstrategiaWeb\ContentTypes;

use EstrategiaWeb\Admin\Menu;

defined( 'ABSPATH' ) || exit;

final class Page {

	public const SLUG = 'estrategia-web-content-types';
	public const HANDLE = 'estrategia-web-constructor';
	public const MODEL = 'estrategia-web-constructor-model';

	/** The notices a list or edit screen shows after an action (?ewct_done=…&ewct_name=…). */
	private const DONE = array( 'added', 'activated', 'deactivated', 'deleted', 'not-found' );

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		// WordPress takes these out of the address bar once the notice is shown (wp_admin_canonical_url()).
		add_filter( 'removable_query_args', static fn( array $args ) => array_merge( $args, array( 'ewct_done', 'ewct_name' ) ) );
	}

	public static function menu(): void {
		$hook = add_submenu_page( Menu::PARENT, __( 'Content types', 'estrategia-web' ), __( 'Content types', 'estrategia-web' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'load' ) );
		}
	}

	/** The list's URL, plus $args. */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/** The ?type= asked for ('' = none). */
	private static function asked(): string {
		return isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
	}

	private static function exists( string $name ): bool {
		return '' !== $name && ( null !== Store::get( $name ) || isset( Loader::load()['files'][ $name ] ) );
	}

	/** Before the header: a ?type= that is not there goes to the list (see «OLD URLS»). */
	public static function load(): void {
		if ( ! isset( $_GET['type'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$name = self::asked();
		if ( ! self::exists( $name ) ) {
			wp_safe_redirect( self::url( array( 'ewct_done' => 'not-found', 'ewct_name' => $name ) ) );
			exit;
		}
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_script( self::MODEL, ESTRATEGIA_WEB_CONTENT_TYPES_URL . 'assets/constructor-model.js', array(), ESTRATEGIA_WEB_VERSION, true );
		wp_enqueue_script( self::HANDLE, ESTRATEGIA_WEB_CONTENT_TYPES_URL . 'assets/constructor.js', array( 'wp-api-fetch', 'wp-a11y', self::MODEL ), ESTRATEGIA_WEB_VERSION, true );
		wp_enqueue_style( self::HANDLE, ESTRATEGIA_WEB_CONTENT_TYPES_URL . 'assets/constructor.css', array(), ESTRATEGIA_WEB_VERSION );
		$config = array(
			'route'   => '/' . Rest::NS . '/content-types',
			'listUrl' => self::url(),
			'strings' => self::strings(),
		);
		wp_add_inline_script( self::HANDLE, 'window.estrategiaWebConstructor = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require_once __DIR__ . '/class-types-table.php';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$new = ! empty( $_GET['new'] );
		if ( $new || '' !== self::asked() ) {
			self::render_edit( $new ? '' : self::asked() );
		} else {
			self::render_list();
		}
	}

	/** The notice of ?ewct_done= (after an action of the list, or a stale link). */
	private static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a message, nothing is done.
		$done = isset( $_GET['ewct_done'] ) ? sanitize_key( wp_unslash( $_GET['ewct_done'] ) ) : '';
		$name = isset( $_GET['ewct_name'] ) ? sanitize_key( wp_unslash( $_GET['ewct_name'] ) ) : '';
		// phpcs:enable
		if ( ! in_array( $done, self::DONE, true ) ) {
			return;
		}
		$texts = array(
			/* translators: %s: internal name of a content type */
			'added'       => __( 'Template added as “%s”. You can change it here.', 'estrategia-web' ),
			/* translators: %s: internal name of a content type */
			'activated'   => __( 'Content type “%s” activated.', 'estrategia-web' ),
			/* translators: %s: internal name of a content type */
			'deactivated' => __( 'Content type “%s” deactivated. Its entries stay and come back when you activate it.', 'estrategia-web' ),
			/* translators: %s: internal name of a content type */
			'deleted'     => __( 'Content type “%s” deleted.', 'estrategia-web' ),
			/* translators: %s: internal name of a content type */
			'not-found'   => __( 'There is no content type “%s” (it may have been deleted or renamed). These are the ones there are.', 'estrategia-web' ),
		);
		$kind = 'not-found' === $done ? 'warning' : 'success';
		printf( '<div class="notice notice-%1$s is-dismissible" data-done="%2$s"><p>%3$s</p></div>', esc_attr( $kind ), esc_attr( $done ), esc_html( sprintf( $texts[ $done ], $name ) ) );
	}

	private static function render_list(): void {
		$data  = Rest::index();
		$table = new Types_Table( $data );
		$table->prepare_items();

		echo '<div class="wrap estrategia-web-content-types">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Content types', 'estrategia-web' ) . '</h1> ';
		echo '<a href="' . esc_url( self::url( array( 'new' => '1' ) ) ) . '" class="page-title-action" data-action="add">' . esc_html__( 'Add new', 'estrategia-web' ) . '</a> ';
		echo '<span class="ewct-menu-wrap">';
		echo '<button type="button" class="page-title-action ewct-menu-button" aria-expanded="false" aria-controls="ewct-templates" data-action="templates">' . esc_html__( 'Add from template', 'estrategia-web' ) . '</button>';
		echo '<div id="ewct-templates" class="ewct-menu" hidden><p class="description">' . esc_html__( 'Ready-made types of a corporate site. Adding one copies it into this panel, where you can change it.', 'estrategia-web' ) . '</p><ul>';
		foreach ( $data['templates'] as $tpl ) {
			$why = $tpl['added'] ? __( 'Already added', 'estrategia-web' ) : ( $tpl['taken'] ? __( 'Not available: a type with this name is registered elsewhere (Company details › Content types).', 'estrategia-web' ) : '' );
			printf(
				'<li><button type="button" class="button-link" data-template="%1$s" data-name="%2$s"%3$s%4$s>%5$s</button>%6$s</li>',
				esc_attr( $tpl['key'] ),
				esc_attr( $tpl['name'] ),
				'' !== $why ? ' disabled' : '',
				'' !== $why ? ' aria-describedby="ewct-tpl-' . esc_attr( $tpl['key'] ) . '"' : '',
				esc_html( $tpl['label'] ),
				'' !== $why ? ' <span class="description" id="ewct-tpl-' . esc_attr( $tpl['key'] ) . '">' . esc_html( $why ) . '</span>' : ''
			);
		}
		echo '</ul></div></span> ';
		echo '<a href="' . esc_url( self::url( array( 'new' => '1', 'import' => '1' ) ) ) . '" class="page-title-action" data-action="import">' . esc_html__( 'Import JSON', 'estrategia-web' ) . '</a>';
		echo '<hr class="wp-header-end">';
		self::notice();
		echo '<p>' . esc_html__( 'Your own content types — restaurants, courses, team members… — with their fields, taxonomies and addresses. They are saved in this site’s database.', 'estrategia-web' ) . '</p>';
		// constructor.js puts its two regions (progress, errors) here and wires the buttons of the rows.
		echo '<div id="estrategia-web-constructor" data-view="list"></div>';
		$table->display();
		if ( $data['broken'] ) {
			echo '<h2>' . esc_html__( 'Files with errors (not registered)', 'estrategia-web' ) . '</h2>';
			foreach ( $data['broken'] as $broken ) {
				$file = basename( (string) $broken['source'] );
				echo '<div class="notice notice-error inline" data-file="' . esc_attr( $file ) . '"><p><code>' . esc_html( (string) $broken['source'] ) . '</code></p><ul class="ul-disc">';
				foreach ( $broken['errors'] as $error ) {
					echo '<li>' . esc_html( (string) $error ) . '</li>';
				}
				echo '</ul></div>';
			}
		}
		echo '<details class="ewct-dirs"><summary>' . esc_html__( 'Folders searched for JSON files', 'estrategia-web' ) . '</summary><ul class="ul-disc">';
		foreach ( $data['dirs'] as $dir ) {
			echo '<li><code>' . esc_html( (string) $dir ) . '</code></li>';
		}
		echo '</ul></details>';
		echo '<noscript><p>' . esc_html__( 'This screen needs JavaScript.', 'estrategia-web' ) . '</p></noscript></div>';
	}

	private static function render_edit( string $name ): void {
		$title = __( 'New content type', 'estrategia-web' );
		if ( '' !== $name ) {
			$data  = Rest::index();
			$item  = current( array_filter( $data['types'], static fn( $t ) => $t['name'] === $name ) );
			$label = $item ? ( new Types_Table( $data ) )->label( $item ) : $name;
			/* translators: %s: a content type name */
			$title = sprintf( __( 'Edit “%s”', 'estrategia-web' ), $label );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$import = ! empty( $_GET['import'] );
		echo '<div class="wrap estrategia-web-content-types">';
		echo '<h1 class="wp-heading-inline" id="ewct-title">' . esc_html( $title ) . '</h1> ';
		echo '<a href="' . esc_url( self::url() ) . '" class="page-title-action" data-action="back">' . esc_html__( 'Back to the list', 'estrategia-web' ) . '</a>';
		echo '<hr class="wp-header-end">';
		self::notice();
		printf( '<div id="estrategia-web-constructor" data-view="edit" data-type="%1$s"%2$s data-loading="1"><p>%3$s</p></div>', esc_attr( $name ), $import ? ' data-import="1"' : '', esc_html__( 'Loading…', 'estrategia-web' ) );
		echo '<noscript><p>' . esc_html__( 'This screen needs JavaScript.', 'estrategia-web' ) . '</p></noscript></div>';
	}

	/** Every text of assets/constructor.js, translated. */
	public static function strings(): array {
		return array(
			/* translators: %s: a content type name */
			'confirmOff'       => __( 'Deactivate “%s”? Its menu and its addresses disappear; its entries stay and come back when you activate it.', 'estrategia-web' ),
			/* translators: %s: a content type name */
			'confirmDelete'    => __( 'Delete the definition of “%s”? This cannot be undone.', 'estrategia-web' ),
			'importFile'       => __( 'JSON file', 'estrategia-web' ),
			'importBad'        => __( 'That is not valid JSON.', 'estrategia-web' ),
			/* translators: 1: how many fields, 2: their names, comma separated */
			'templateNewFields' => __( 'The template this type comes from has new fields (%1$d): %2$s. They are not added by themselves: add them here and save.', 'estrategia-web' ),
			'templateAddFields' => __( 'Add the new fields of the template', 'estrategia-web' ),
			'fileNotice'       => __( 'This type is defined in a file, which is read-only. Saving here creates its copy in this panel, which wins from then on.', 'estrategia-web' ),
			/* translators: %s: a content type name */
			'editing'          => __( 'Edit “%s”', 'estrategia-web' ),
			'settings'         => __( 'Settings', 'estrategia-web' ),
			'tabGeneral'       => __( 'General', 'estrategia-web' ),
			'tabUrls'          => __( 'URLs & languages', 'estrategia-web' ),
			'tabAdvanced'      => __( 'Advanced', 'estrategia-web' ),
			/* translators: %s: a language code such as es */
			'plural'           => __( 'Name, plural (%s)', 'estrategia-web' ),
			/* translators: %s: a language code such as es */
			'singular'         => __( 'Name, singular (%s)', 'estrategia-web' ),
			'prefix'           => __( 'Prefix', 'estrategia-web' ),
			'prefixHelp'       => __( 'The site’s own, 2 to 9 letters or digits and “_” (e.g. “gment_”). Every internal name starts with it.', 'estrategia-web' ),
			'internal'         => __( 'Internal name', 'estrategia-web' ),
			'internalHelp'     => __( 'Lowercase letters, digits and “_”, at most 20 characters. It cannot be changed once there are entries.', 'estrategia-web' ),
			'slug'             => __( 'Address (URL)', 'estrategia-web' ),
			'slugHelp'         => __( 'The part of the address after the domain: /restaurants/. Changing it moves the published addresses.', 'estrategia-web' ),
			'urlsHelp'         => __( 'The address of this type in the other languages of the site. Empty: the first language’s is used.', 'estrategia-web' ),
			'urlsOneLanguage'  => __( 'The site has one language: the address is set in General.', 'estrategia-web' ),
			'icon'             => __( 'Menu icon', 'estrategia-web' ),
			'iconHelp'         => __( 'A Dashicons name, e.g. dashicons-food.', 'estrategia-web' ),
			'menuPosition'     => __( 'Position in the menu', 'estrategia-web' ),
			'menuPositionHelp' => __( 'A whole number: 5 below Posts, 20 below Pages, 25 below Comments. Empty: at the end.', 'estrategia-web' ),
			'supports'         => __( 'What the editor offers', 'estrategia-web' ),
			'public'           => __( 'Public (has its own pages on the site)', 'estrategia-web' ),
			'hasArchive'       => __( 'Has a list page at its address', 'estrategia-web' ),
			'wpmlType'         => __( 'Translation (WPML)', 'estrategia-web' ),
			'wpmlTranslate'    => __( 'Translate', 'estrategia-web' ),
			'wpmlDisplay'      => __( 'Translate, and show the original until it is translated', 'estrategia-web' ),
			'wpmlIgnore'       => __( 'Do not translate', 'estrategia-web' ),
			'fieldsBox'        => __( 'Where the fields are edited', 'estrategia-web' ),
			'fieldsBoxSidebar' => __( 'In the editor’s sidebar (the «details» panel)', 'estrategia-web' ),
			'fieldsBoxMain'    => __( 'In a wide box under the content, with its groups', 'estrategia-web' ),
			'fieldsBoxHelp'    => __( 'A type without «Content (block editor)» always gets the box under the content.', 'estrategia-web' ),
			'groups'           => __( 'Field groups', 'estrategia-web' ),
			'groupsHelp'       => __( 'Sections of the box under the content. A field joins one in its settings («Group»); fields without a group go first.', 'estrategia-web' ),
			/* translators: %d: position of a group in the list */
			'group'            => __( 'Group %d', 'estrategia-web' ),
			'addGroup'         => __( 'Add group', 'estrategia-web' ),
			'groupKey'         => __( 'Key', 'estrategia-web' ),
			'groupKeyHelp'     => __( 'Lowercase letters, digits and “_”, starting with a letter. The fields refer to it.', 'estrategia-web' ),
			/* translators: %s: a language code such as es */
			'groupLabel'       => __( 'Title (%s)', 'estrategia-web' ),
			'groupCollapsed'   => __( 'Closed when the screen opens', 'estrategia-web' ),
			'fieldGroup'       => __( 'Group', 'estrategia-web' ),
			'noGroup'          => __( 'No group', 'estrategia-web' ),
			'sectionTaxonomies' => __( 'Taxonomies', 'estrategia-web' ),
			/* translators: %d: position of a taxonomy in the list */
			'taxonomy'         => __( 'Taxonomy %d', 'estrategia-web' ),
			'addTaxonomy'      => __( 'Add taxonomy', 'estrategia-web' ),
			'hierarchical'     => __( 'Hierarchical (like categories)', 'estrategia-web' ),
			/* translators: %s: a taxonomy name */
			'taxonomyRef'      => __( 'Uses the taxonomy “%s”, declared elsewhere.', 'estrategia-web' ),
			'sectionFields'    => __( 'Fields', 'estrategia-web' ),
			'noFields'         => __( 'No fields yet.', 'estrategia-web' ),
			'reorderHelp'      => __( 'Open a field to change it. To reorder, drag a row by its handle, or use Alt + Up or Down arrow on a field (or its Move buttons).', 'estrategia-web' ),
			'colLabel'         => __( 'Label', 'estrategia-web' ),
			'colFieldName'     => __( 'Name', 'estrategia-web' ),
			'colFieldType'     => __( 'Type', 'estrategia-web' ),
			'noLabel'          => __( '(no label)', 'estrategia-web' ),
			'dragHandle'       => __( 'Drag to reorder', 'estrategia-web' ),
			/* translators: %s: a field label */
			'moveUpField'      => __( 'Move “%s” up', 'estrategia-web' ),
			/* translators: %s: a field label */
			'moveDownField'    => __( 'Move “%s” down', 'estrategia-web' ),
			/* translators: %s: a field label */
			'removeField'      => __( 'Remove “%s”', 'estrategia-web' ),
			/* translators: 1: a field label, 2: its new position, 3: how many fields */
			'moved'            => __( '“%1$s” moved to position %2$d of %3$d.', 'estrategia-web' ),
			/* translators: %s: a field label */
			'removed'          => __( '“%s” removed. Save to keep the change.', 'estrategia-web' ),
			'addField'         => __( 'Add field', 'estrategia-web' ),
			'fieldName'        => __( 'Internal name (meta key)', 'estrategia-web' ),
			'fieldNameLocked'  => __( 'It cannot be renamed: its data is stored under this name. To change it, remove the field and add a new one.', 'estrategia-web' ),
			'fieldType'        => __( 'Type', 'estrategia-web' ),
			/* translators: %s: a language code such as es */
			'label'            => __( 'Label (%s)', 'estrategia-web' ),
			/* translators: %s: a language code such as es */
			'help'             => __( 'Help (%s)', 'estrategia-web' ),
			'required'         => __( 'Required to publish', 'estrategia-web' ),
			'default'          => __( 'Default value', 'estrategia-web' ),
			'wpmlField'        => __( 'In translations', 'estrategia-web' ),
			'wpmlAuto'         => __( 'Automatic (texts translated, the rest copied)', 'estrategia-web' ),
			'wpmlCopy'         => __( 'Copy from the original', 'estrategia-web' ),
			'wpmlFieldTranslate' => __( 'Translate', 'estrategia-web' ),
			'wpmlFieldIgnore'  => __( 'Ignore', 'estrategia-web' ),
			'options'          => __( 'Options, one per line: value | label', 'estrategia-web' ),
			'postTypes'        => __( 'Related types (internal names, separated by commas)', 'estrategia-web' ),
			'min'              => __( 'Minimum', 'estrategia-web' ),
			'max'              => __( 'Maximum', 'estrategia-web' ),
			'integer'          => __( 'Whole numbers only', 'estrategia-web' ),
			'trueLabel'        => __( 'Text when yes (empty: «Yes»)', 'estrategia-web' ),
			'falseLabel'       => __( 'Text when no (empty: «No»)', 'estrategia-web' ),
			'moveUp'           => __( 'Move up', 'estrategia-web' ),
			'moveDown'         => __( 'Move down', 'estrategia-web' ),
			'remove'           => __( 'Remove', 'estrategia-web' ),
			'json'             => __( 'JSON (advanced)', 'estrategia-web' ),
			'jsonHelp'         => __( 'The whole definition. Change it here to set what the form does not show, then apply it.', 'estrategia-web' ),
			'jsonApply'        => __( 'Apply the JSON to the form', 'estrategia-web' ),
			'save'             => __( 'Save', 'estrategia-web' ),
			'saving'           => __( 'Saving…', 'estrategia-web' ),
			'saved'            => __( 'Saved. The changes are visible from the next page you open.', 'estrategia-web' ),
			/* translators: %d: number of redirects */
			'redirectsMade'    => __( 'Redirects created for the old addresses: %d.', 'estrategia-web' ),
			'confirmTitle'     => __( 'Before saving', 'estrategia-web' ),
			'confirmSave'      => __( 'Save anyway', 'estrategia-web' ),
			'cancel'           => __( 'Cancel', 'estrategia-web' ),
			'errorsTitle'      => __( 'Nothing was saved', 'estrategia-web' ),
			'loadError'        => __( 'The list could not be loaded.', 'estrategia-web' ),
			'fieldTypes'       => array(
				'text'        => __( 'Text', 'estrategia-web' ),
				'textarea'    => __( 'Text, several lines', 'estrategia-web' ),
				'number'      => __( 'Number', 'estrategia-web' ),
				'url'         => __( 'Web address', 'estrategia-web' ),
				'email'       => __( 'Email', 'estrategia-web' ),
				'tel'         => __( 'Phone', 'estrategia-web' ),
				'image'       => __( 'Image', 'estrategia-web' ),
				'date'        => __( 'Date', 'estrategia-web' ),
				'select'      => __( 'Choice from a list', 'estrategia-web' ),
				'relation'    => __( 'Link to another entry', 'estrategia-web' ),
				'boolean'     => __( 'Yes / no', 'estrategia-web' ),
				'rating'      => __( 'Rating (stars)', 'estrategia-web' ),
				'embed'       => __( 'Embedded map or video', 'estrategia-web' ),
				'local_image' => __( 'Image of this site by path', 'estrategia-web' ),
				'hours'       => __( 'Opening hours (days and times)', 'estrategia-web' ),
			),
			'supportNames'     => array(
				'title'           => __( 'Title', 'estrategia-web' ),
				'editor'          => __( 'Content (block editor)', 'estrategia-web' ),
				'author'          => __( 'Author', 'estrategia-web' ),
				'thumbnail'       => __( 'Featured image', 'estrategia-web' ),
				'excerpt'         => __( 'Excerpt', 'estrategia-web' ),
				'trackbacks'      => __( 'Trackbacks', 'estrategia-web' ),
				'custom-fields'   => __( 'Custom fields', 'estrategia-web' ),
				'comments'        => __( 'Comments', 'estrategia-web' ),
				'revisions'       => __( 'Revisions', 'estrategia-web' ),
				'page-attributes' => __( 'Order', 'estrategia-web' ),
				'post-formats'    => __( 'Formats', 'estrategia-web' ),
			),
		);
	}
}
