<?php
/**
 * La definición de un formulario: qué campos tiene y qué avisos manda.
 *
 * EL PUNTO DE SEGURIDAD DEL PLUGIN. La configuración vive en los bloques
 * (post_content), y el navegador del visitante NO la recibe: al pintar el
 * formulario, el servidor saca de los bloques los campos y los avisos, los
 * guarda aquí con su hash y en el HTML solo pone ese hash. Al recibir un
 * envío, el servidor carga la definición por el hash y valida y avisa con
 * ella. El visitante no puede añadir campos, quitar un obligatorio ni cambiar
 * un destinatario: solo puede señalar una definición que el servidor pintó.
 *
 * Por qué una foto y no volver a leer la página al recibir el envío: un
 * formulario puede estar en una página, en un patrón sincronizado, en una
 * parte de plantilla o en un fichero del tema. La foto funciona en todos esos
 * sitios sin saber dónde está, y valida contra lo que el visitante VIO.
 *
 * Cómo se mantiene:
 * - Un atributo nuevo de un bloque de campo que afecte a la validación o al
 *   correo entra en field_from_block(), o el servidor no se enterará de él.
 * - Cambiar el formato de la definición es cambiar FORMAT: las fotos viejas
 *   siguen sirviendo a las páginas en caché, así que load() tiene que seguir
 *   entendiendo las anteriores.
 * - Caso frontera: dos copias del mismo patrón en dos páginas comparten
 *   formId. Es a propósito: el registro las agrupa como «el mismo formulario».
 *   Si deben contarse aparte, se les cambia el identificador en el editor.
 * - La trampa: las fotos no se purgan. Una página en caché sigue apuntando a
 *   la suya aunque el formulario se haya editado después, y borrarla rompería
 *   ese envío. Son unos pocos KB por versión del formulario.
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

	/** @var array<string, bool> Hashes ya guardados en esta petición. */
	private static array $stored = array();

	/**
	 * Construye la definición a partir del bloque del formulario ya analizado.
	 *
	 * @param array<string, mixed> $attributes Atributos del bloque formulario.
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

	/** Guarda la foto si no existe y devuelve su hash. */
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

	/** Identificadores: minúsculas, números, guion y guion bajo. */
	public static function clean_id( string $value ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ), 0, 64 );
	}

	/**
	 * Recorre los bloques interiores, también dentro de grupos y columnas: el
	 * formulario se maqueta como cualquier bloque.
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
