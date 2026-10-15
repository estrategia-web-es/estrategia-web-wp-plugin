<?php
/**
 * A form's definition: which fields it has and which notifications it sends.
 *
 * THE PLUGIN'S SECURITY POINT. The configuration lives in the blocks
 * (post_content), and the visitor's browser does NOT receive it: when the
 * form is rendered, the server extracts the fields and notifications from
 * the blocks, stores them here with their hash and puts only that hash in
 * the HTML. When a submission arrives, the server loads the definition by
 * the hash and validates and notifies with it. The visitor cannot add
 * fields, drop a required one or change a recipient: they can only point to
 * a definition the server rendered.
 *
 * Why a snapshot and not re-reading the page when the submission arrives: a
 * form can be in a page, a synced pattern, a template part or a theme file.
 * The snapshot works in all those places without knowing where it is, and
 * validates against what the visitor SAW.
 *
 * How to maintain:
 * - A new attribute of a field block that affects validation or the email
 *   goes into field_from_block(), or the server will never know about it.
 * - Changing the definition format means changing FORMAT: old snapshots
 *   still serve cached pages, so load() must keep understanding the
 *   previous ones.
 * - Edge case: two copies of the same pattern on two pages share a formId.
 *   This is on purpose: the log groups them as «the same form». If they
 *   must be counted separately, change their identifier in the editor.
 * - The trap: snapshots are not purged. A cached page keeps pointing to its
 *   own even if the form has been edited since, and deleting it would break
 *   that submission. They are a few KB per form version.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Definitions {

	public const FORMAT = 1;

	public const FIELD_BLOCKS = array(
		'estrategia-web-forms/text'     => 'text',
		'estrategia-web-forms/email'    => 'email',
		'estrategia-web-forms/tel'      => 'tel',
		'estrategia-web-forms/textarea' => 'textarea',
		'estrategia-web-forms/select'   => 'select',
		'estrategia-web-forms/checkbox' => 'checkbox',
	);

	/** @var array<string, bool> Hashes already stored in this request. */
	private static array $stored = array();

	/**
	 * Builds the definition from the already parsed form block.
	 *
	 * @param array<string, mixed> $attributes Attributes of the form block.
	 * @param array<int, array>    $inner      parsed_block['innerBlocks'].
	 */
	public static function build( array $attributes, array $inner ): array {
		$fields        = array();
		$notifications = array();
		self::collect( $inner, $fields, $notifications );

		return array(
			'format'          => self::FORMAT,
			'form_id'         => self::clean_id( (string) ( $attributes['formId'] ?? '' ) ),
			'title'           => sanitize_text_field( (string) ( $attributes['title'] ?? '' ) ),
			'post_id'         => (int) get_queried_object_id(),
			'lang'            => Language::current(),
			'success_message' => sanitize_textarea_field( (string) ( $attributes['successMessage'] ?? '' ) ),
			'error_message'   => sanitize_textarea_field( (string) ( $attributes['errorMessage'] ?? '' ) ),
			'fields'          => array_values( $fields ),
			'notifications'   => $notifications,
		);
	}

	public static function hash( array $definition ): string {
		return md5( (string) wp_json_encode( $definition ) );
	}

	/** Stores the snapshot if it does not exist and returns its hash. */
	public static function store( array $definition ): string {
		$hash = self::hash( $definition );
		if ( isset( self::$stored[ $hash ] ) ) {
			return $hash;
		}
		global $wpdb;
		$table  = Migrations::definitions_table();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT hash FROM $table WHERE hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $exists ) {
			$wpdb->insert(
				$table,
				array(
					'hash'       => $hash,
					'form_id'    => $definition['form_id'],
					'form_title' => $definition['title'],
					'post_id'    => $definition['post_id'],
					'lang'       => $definition['lang'],
					'definition' => wp_json_encode( $definition ),
					'created_at' => current_time( 'mysql', true ),
				)
			);
		}
		self::$stored[ $hash ] = true;
		return $hash;
	}

	public static function load( string $hash ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
			return null;
		}
		global $wpdb;
		$table = Migrations::definitions_table();
		$json  = $wpdb->get_var( $wpdb->prepare( "SELECT definition FROM $table WHERE hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$def   = $json ? json_decode( (string) $json, true ) : null;
		return is_array( $def ) ? $def : null;
	}

	/** Identifiers: lowercase letters, digits, hyphen and underscore. */
	public static function clean_id( string $value ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ), 0, 64 );
	}

	/**
	 * Walks the inner blocks, also inside groups and columns: the form is
	 * laid out like any other block.
	 */
	private static function collect( array $blocks, array &$fields, array &$notifications ): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			$type = $registry->get_registered( $name );
			$atts = $type ? $type->prepare_attributes_for_render( (array) ( $block['attrs'] ?? array() ) ) : (array) ( $block['attrs'] ?? array() );

			if ( isset( self::FIELD_BLOCKS[ $name ] ) ) {
				$field = self::field_from_block( self::FIELD_BLOCKS[ $name ], $atts );
				if ( '' !== $field['name'] && ! isset( $fields[ $field['name'] ] ) ) {
					$fields[ $field['name'] ] = $field;
				}
			} elseif ( 'estrategia-web-forms/notification' === $name ) {
				$notifications[] = array(
					'label'    => sanitize_text_field( (string) ( $atts['label'] ?? '' ) ),
					'to'       => sanitize_text_field( (string) ( $atts['to'] ?? '' ) ),
					'reply_to' => sanitize_text_field( (string) ( $atts['replyTo'] ?? '' ) ),
					'subject'  => sanitize_text_field( (string) ( $atts['subject'] ?? '' ) ),
					'body'     => sanitize_textarea_field( (string) ( $atts['body'] ?? '' ) ),
				);
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect( $block['innerBlocks'], $fields, $notifications );
			}
		}
	}

	private static function field_from_block( string $type, array $atts ): array {
		$consent = 'checkbox' === $type && ! empty( $atts['consent'] );
		return array(
			'type'     => $type,
			'name'     => self::clean_id( (string) ( $atts['name'] ?? '' ) ),
			'label'    => wp_strip_all_tags( Blocks::field_label( $type, $atts ) ),
			'required' => $consent || ! empty( $atts['required'] ),
			'options'  => 'select' === $type ? Blocks::options( (string) ( $atts['options'] ?? '' ) ) : array(),
			'consent'  => $consent,
		);
	}
}
