<?php
/**
 * Module Name: WhatsApp button
 * Description: Floating button that opens a WhatsApp chat with a predefined message. The number and the message are site options, not per page.
 * Default: on
 * Requires:
 *
 * Block estrategia-web/whatsapp. Until 1.0.0 the parent theme registered it,
 * with its two options (see modules/carousel/module.php: the same move).
 * Everything it does, in the header of blocks/whatsapp/render.php. The theme
 * puts it in its footer (parts/footer.html): with this module off, the
 * footer renders without the button and without error.
 *
 * Options (the 1.0.0 names): estrategia_web_whatsapp_phone and
 * estrategia_web_whatsapp_message. No screen of its own (a settings page is
 * not built for two fields):
 *     wp option update estrategia_web_whatsapp_phone 34600111222
 *     wp option update estrategia_web_whatsapp_message "Hi, I'm writing from the website"
 * or via REST at /wp/v2/settings (hence show_in_rest).
 *
 * Alias: if the new options do not exist, the “Armazón” ones are read
 * (armazon_whatsapp_*); nothing is written here, migrating is the migrator's
 * job. Removed in 2.0.0, with the other aliases.
 *
 * Block Bindings: phone, message and label can be bound (the company's
 * number, from the site module's source). See the explanation in
 * modules/map/module.php.
 *
 * Tests: in the theme repo (armazon-wp-padre/tests/whatsapp.test.mjs).
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
