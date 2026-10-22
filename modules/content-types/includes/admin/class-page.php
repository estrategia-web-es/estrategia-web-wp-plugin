<?php
/**
 * «Estrategia Web → Content types»: THE CONSTRUCTOR (17-10-2026). Until then
 * this screen only listed the JSON files of the theme; now the definitions
 * are data of each site (class-store.php) and this is where they are made:
 * a list of the types (from the panel, from a template, from a file), the
 * templates that can be added («Add testimonials»), import and export of a
 * definition as JSON, and the editor of one type — names and URL per
 * language, supports, taxonomies and fields with every option of the schema.
 *
 * The page is painted by assets/constructor.js: a classic script, NO BUILD
 * STEP, like the rest of the plugin, plain DOM (no React: a form that saves
 * through REST does not need it). Everything it reads and writes goes
 * through the REST route (class-rest.php), which validates; nothing is saved
 * from here. PHP gives it the REST path and its texts already translated
 * (strings()), so the translations live in the .po like any PHP text.
 *
 * ACCESSIBLE ON PURPOSE: every control has its <label>, groups are
 * fieldsets with a legend, errors and confirmations go to a region that gets
 * the focus (role="alert"), progress to a polite live region, and nothing
 * depends on colour or on a mouse. tests/constructor-editor.test.mjs drives
 * it with the keyboard-reachable controls only.
 *
 * How to maintain:
 * - Who and when: whoever adds a key to the schema that a person should set
 *   from the panel: its control in constructor.js (form()), its text here.
 *   A key the form does not show is KEPT as it is (the form edits a copy of
 *   the raw definition), and the «JSON» box at the bottom edits anything.
 * - Boundary case, with a name: a type made from a TEMPLATE. Its internal
 *   name and the fields the template ships cannot be renamed (their stored
 *   data would be orphaned); everything else can be edited.
 * - The trap: a text with a placeholder is used in JS with fill() (%s, %1$s,
 *   %2$s): keep the translators: comment here, the .po is where it is read.
 */

namespace EstrategiaWeb\ContentTypes;

use EstrategiaWeb\Admin\Modules_Page;

defined( 'ABSPATH' ) || exit;

final class Page {

	public const SLUG = 'estrategia-web-content-types';
	public const HANDLE = 'estrategia-web-constructor';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( Modules_Page::MENU, __( 'Content types', 'estrategia-web' ), __( 'Content types', 'estrategia-web' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_script( self::HANDLE, ESTRATEGIA_WEB_CONTENT_TYPES_URL . 'assets/constructor.js', array( 'wp-api-fetch' ), ESTRATEGIA_WEB_VERSION, true );
		wp_enqueue_style( self::HANDLE, ESTRATEGIA_WEB_CONTENT_TYPES_URL . 'assets/constructor.css', array(), ESTRATEGIA_WEB_VERSION );
		$config = array(
			'route'   => '/' . Rest::NS . '/content-types',
			'strings' => self::strings(),
		);
		wp_add_inline_script( self::HANDLE, 'window.estrategiaWebConstructor = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap estrategia-web-content-types"><h1>' . esc_html__( 'Content types', 'estrategia-web' ) . '</h1>';
		echo '<p>' . esc_html__( 'Your own content types — restaurants, courses, team members… — with their fields, taxonomies and addresses. They are saved in this site’s database.', 'estrategia-web' ) . '</p>';
		echo '<div id="estrategia-web-constructor" data-loading="1"><p>' . esc_html__( 'Loading…', 'estrategia-web' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'This screen needs JavaScript.', 'estrategia-web' ) . '</p></noscript></div>';
	}

	/** Every text of assets/constructor.js, translated. */
	public static function strings(): array {
		return array(
			'types'            => __( 'Types', 'estrategia-web' ),
			'none'             => __( 'There are no content types yet. Add one, start from a template or import a JSON file.', 'estrategia-web' ),
			'colName'          => __( 'Name', 'estrategia-web' ),
			'colInternal'      => __( 'Internal name', 'estrategia-web' ),
			'colUrl'           => __( 'URL', 'estrategia-web' ),
			'colSource'        => __( 'Where it is defined', 'estrategia-web' ),
			'colStatus'        => __( 'Status', 'estrategia-web' ),
			'colEntries'       => __( 'Entries', 'estrategia-web' ),
			'colActions'       => __( 'Actions', 'estrategia-web' ),
			'sourceDatabase'   => __( 'This panel', 'estrategia-web' ),
			/* translators: %s: a template name, e.g. Testimonials */
			'sourceTemplate'   => __( 'This panel (from the template “%s”)', 'estrategia-web' ),
			/* translators: %s: a file path */
			'sourceFile'       => __( 'File (read-only): %s', 'estrategia-web' ),
			'active'           => __( 'Active', 'estrategia-web' ),
			'inactive'         => __( 'Switched off', 'estrategia-web' ),
			'withErrors'       => __( 'Not registered: it has errors', 'estrategia-web' ),
			'edit'             => __( 'Edit', 'estrategia-web' ),
			'export'           => __( 'Export JSON', 'estrategia-web' ),
			'copyToPanel'      => __( 'Copy to this panel', 'estrategia-web' ),
			'switchOff'        => __( 'Switch off', 'estrategia-web' ),
			'switchOn'         => __( 'Switch on', 'estrategia-web' ),
			'delete'           => __( 'Delete', 'estrategia-web' ),
			/* translators: %s: a content type name */
			'confirmOff'       => __( 'Switch off “%s”? Its menu and its addresses disappear; its entries stay and come back when you switch it on.', 'estrategia-web' ),
			/* translators: %s: a content type name */
			'confirmDelete'    => __( 'Delete the definition of “%s”? This cannot be undone.', 'estrategia-web' ),
			'add'              => __( 'Add content type', 'estrategia-web' ),
			'import'           => __( 'Import JSON', 'estrategia-web' ),
			'importFile'       => __( 'JSON file', 'estrategia-web' ),
			'importPaste'      => __( 'Or paste the JSON here', 'estrategia-web' ),
			'importDo'         => __( 'Import', 'estrategia-web' ),
			'importBad'        => __( 'That is not valid JSON.', 'estrategia-web' ),
			'templates'        => __( 'Templates', 'estrategia-web' ),
			'templatesHelp'    => __( 'Ready-made types of a corporate site. Adding one copies it into this panel, where you can change it.', 'estrategia-web' ),
			/* translators: %s: a template name, e.g. Testimonials */
			'addTemplate'      => __( 'Add %s', 'estrategia-web' ),
			'templateAdded'    => __( 'Already added', 'estrategia-web' ),
			/* translators: 1: how many fields, 2: their names, comma separated */
			'templateNewFields' => __( 'The template this type comes from has new fields (%1$d): %2$s. They are not added by themselves: add them here and save.', 'estrategia-web' ),
			'templateAddFields' => __( 'Add the new fields of the template', 'estrategia-web' ),
			'templateTaken'    => __( 'Not available: a type with this name is registered elsewhere (Company details › Content types).', 'estrategia-web' ),
			'broken'           => __( 'Files with errors (not registered)', 'estrategia-web' ),
			'dirs'             => __( 'Folders searched for JSON files', 'estrategia-web' ),
			'back'             => __( 'Back to the list', 'estrategia-web' ),
			/* translators: %s: a content type name */
			'editing'          => __( 'Edit “%s”', 'estrategia-web' ),
			'creating'         => __( 'New content type', 'estrategia-web' ),
			'sectionType'      => __( 'The type', 'estrategia-web' ),
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
			'icon'             => __( 'Menu icon', 'estrategia-web' ),
			'iconHelp'         => __( 'A Dashicons name, e.g. dashicons-food.', 'estrategia-web' ),
			'supports'         => __( 'What the editor offers', 'estrategia-web' ),
			'public'           => __( 'Public (has its own pages on the site)', 'estrategia-web' ),
			'hasArchive'       => __( 'Has a list page at its address', 'estrategia-web' ),
			'wpmlType'         => __( 'Translation (WPML)', 'estrategia-web' ),
			'wpmlTranslate'    => __( 'Translate', 'estrategia-web' ),
			'wpmlDisplay'      => __( 'Translate, and show the original until it is translated', 'estrategia-web' ),
			'wpmlIgnore'       => __( 'Do not translate', 'estrategia-web' ),
			'sectionTaxonomies' => __( 'Taxonomies', 'estrategia-web' ),
			/* translators: %d: position of a taxonomy in the list */
			'taxonomy'         => __( 'Taxonomy %d', 'estrategia-web' ),
			'addTaxonomy'      => __( 'Add taxonomy', 'estrategia-web' ),
			'hierarchical'     => __( 'Hierarchical (like categories)', 'estrategia-web' ),
			/* translators: %s: a taxonomy name */
			'taxonomyRef'      => __( 'Uses the taxonomy “%s”, declared elsewhere.', 'estrategia-web' ),
			'sectionFields'    => __( 'Fields', 'estrategia-web' ),
			/* translators: 1: position of a field in the list, 2: its label */
			'field'            => __( 'Field %1$d: %2$s', 'estrategia-web' ),
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
