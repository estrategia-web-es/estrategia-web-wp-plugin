<?php
/**
 * Module Name: WhatsApp button
 * Description: Floating button that opens a WhatsApp chat with a predefined message. The number and the message are site options, not per page.
 * Default: on
 * Requires:
 *
 * Bloque estrategia-web/whatsapp. Hasta la 1.0.0 lo registraba el tema padre,
 * con sus dos opciones (ver modules/carousel/module.php: el mismo traslado).
 * Todo lo que hace, en la cabecera de blocks/whatsapp/render.php. El tema lo
 * pone en su pie (parts/footer.html): con este módulo apagado, el pie sale
 * sin botón y sin error.
 *
 * Opciones (los nombres de la 1.0.0): estrategia_web_whatsapp_phone y
 * estrategia_web_whatsapp_message. Sin pantalla propia (no se hace una página
 * de ajustes para dos campos):
 *     wp option update estrategia_web_whatsapp_phone 34600111222
 *     wp option update estrategia_web_whatsapp_message "Hola, os escribo desde la web"
 * o por REST en /wp/v2/settings (por eso show_in_rest).
 *
 * Alias: si las opciones nuevas no existen se leen las de «Armazón»
 * (armazon_whatsapp_*); nada se escribe aquí, migrar es del migrador. Se
 * quita en la 2.0.0, con el resto de alias.
 *
 * Block Bindings: phone, message y label se pueden enlazar (el número de la
 * empresa, de la fuente del módulo site). Ver la explicación en
 * modules/map/module.php.
 *
 * Pruebas: en el repo del tema (armazon-wp-padre/tests/whatsapp.test.mjs).
 */

namespace EstrategiaWeb\Whatsapp;

defined( 'ABSPATH' ) || exit;

add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/whatsapp' ) );

add_action(
	'init',
	static function (): void {
		register_setting(
			'general',
			'estrategia_web_whatsapp_phone',
			array(
				'type'              => 'string',
				'description'       => __( 'WhatsApp phone number in international format, digits only.', 'estrategia-web' ),
				'sanitize_callback' => static fn( $value ) => preg_replace( '/\D+/', '', (string) $value ),
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			'general',
			'estrategia_web_whatsapp_message',
			array(
				'type'              => 'string',
				'description'       => __( 'Predefined WhatsApp message.', 'estrategia-web' ),
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
	}
);

foreach ( array( 'whatsapp_phone', 'whatsapp_message' ) as $estrategia_web_option ) {
	add_filter(
		'default_option_estrategia_web_' . $estrategia_web_option,
		static function ( $default ) use ( $estrategia_web_option ) {
			$legacy = get_option( 'armazon_' . $estrategia_web_option, null );
			return null === $legacy ? $default : $legacy;
		}
	);
}
unset( $estrategia_web_option );

add_filter(
	'block_bindings_supported_attributes_estrategia-web/whatsapp',
	static fn( $supported ) => array_values( array_unique( array_merge( (array) $supported, array( 'phone', 'message', 'label' ) ) ) )
);
