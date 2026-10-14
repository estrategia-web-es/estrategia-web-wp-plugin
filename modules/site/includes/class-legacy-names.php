<?php
/**
 * Los nombres de antes de 1.0.0 (cuando el plugin se llamaba «Armazón Site»),
 * como ALIAS que pintan lo mismo que los nuevos.
 *
 * Qué cubre, y por qué cada cosa:
 * - BLOQUES armazon-site/consent y armazon/consent → estrategia-web-site/consent.
 *   Un alias se registra copiando el tipo nuevo (atributos, supports) y, al
 *   pintar, renombra el bloque (y las clases de su marcado guardado) y lo
 *   pinta con render_block(): sale EXACTAMENTE lo que saldría con el nombre
 *   nuevo. Oculto del insertador.
 * - FUENTES DE BLOCK BINDINGS armazon-site/company y armazon-site/location:
 *   las mismas funciones con el nombre viejo.
 * - SHORTCODES [armazon_company] y [armazon_consent_settings].
 * - La OPCIÓN: si estrategia_web_site no existe todavía y armazon_site sí, se
 *   lee la vieja (default_option_…). Nada se escribe aquí: migrar es cosa
 *   del migrador (class-armazon-migration.php).
 * La cookie del consentimiento tiene su propio respaldo en Consent::choice()
 * y blocks/consent/view.js.
 *
 * Para qué: el MIGRADOR reescribe el contenido con los nombres nuevos, pero
 * puede quedar marcado viejo donde no mira (una revisión que se restaura, una
 * página pegada de otra web, un patrón importado). Con el alias se sigue
 * viendo igual en la web; en el editor, el bloque viejo sale como «no
 * admitido» hasta que se pase el migrador o se cambie a mano.
 *
 * Cómo se mantiene:
 * - Quién: quien toque el plugin. CUÁNDO SE QUITA: en la 2.0.0, una vez
 *   pasado el migrador en todas las webs que usaron Armazón (07-10-2026: solo
 *   Lavandería Mallorca). Quitarlo antes es versión mayor igualmente.
 * - Un bloque, fuente o shortcode nuevo NO entra aquí: aquí solo están los
 *   nombres que existieron con «armazon».
 * - Caso frontera: un bloque viejo DENTRO de un bloque viejo. El de fuera se
 *   renombra entero (también sus hijos) y se pinta una vez; el de dentro no
 *   llega a pasar por su alias.
 * - La trampa: el alias necesita que el tipo nuevo ya esté registrado, por
 *   eso corre en init con prioridad 99 (los bloques se registran en init, 10).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Legacy_Names {

	/** Bloque viejo → bloque nuevo. */
	public const BLOCKS = array(
		'armazon-site/consent' => 'estrategia-web-site/consent',
		'armazon/consent'      => 'estrategia-web-site/consent',
	);

	/** Clases del marcado guardado: viejo → nuevo (lo más largo primero). */
	public const CLASSES = array(
		'wp-block-armazon-site-' => 'wp-block-estrategia-web-site-',
		'wp-block-armazon-'      => 'wp-block-estrategia-web-site-',
		'armazon-consent'        => 'estrategia-web-consent',
	);

	/** Fuente de Block Bindings vieja → nueva. */
	public const SOURCES = array(
		'armazon-site/company'  => 'estrategia-web-site/company',
		'armazon-site/location' => 'estrategia-web-site/location',
	);

	/** Shortcode viejo → nuevo. */
	public const SHORTCODES = array(
		'armazon_company'           => 'estrategia_web_company',
		'armazon_consent_settings'  => 'estrategia_web_consent_settings',
	);

	public const LEGACY_OPTION = 'armazon_site';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ), 99 );
		add_filter( 'default_option_' . Settings::OPTION, array( self::class, 'legacy_option' ), 10, 1 );
	}

	public static function register(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::BLOCKS as $old => $new ) {
			$type = $registry->get_registered( $new );
			if ( ! $type || $registry->is_registered( $old ) ) {
				continue;
			}
			register_block_type(
				$old,
				array(
					'api_version'     => 3,
					'title'           => $type->title,
					'category'        => $type->category,
					'attributes'      => $type->attributes,
					'uses_context'    => $type->uses_context,
					'supports'        => array_merge( (array) $type->supports, array( 'inserter' => false ) ),
					'render_callback' => static fn( $attributes, $content, $block ) => render_block( self::rename( $block->parsed_block ) ),
				)
			);
		}
		// Las fuentes viejas llaman a las mismas funciones (WP_Block_Bindings_Source
		// no deja leer su callback: es privado). La de sedes recibe además las
		// claves de antes de 1.0.0 (location_phone…) y las pasa a las nuevas.
		$legacy_key = static function ( array $args ): array {
			$key = (string) ( $args['key'] ?? '' );
			if ( '' !== $key && ! str_starts_with( $key, 'estrategia_web_' ) && in_array( 'estrategia_web_' . $key, array_merge( Content_Types::meta_keys( Location::TYPE ), Location::DERIVED ), true ) ) {
				$args['key'] = 'estrategia_web_' . $key;
			}
			return $args;
		};
		$sources = array(
			'armazon-site/company'  => array( __( 'Company details', 'estrategia-web' ), array( Bindings::class, 'get_value' ), array() ),
			'armazon-site/location' => array( __( 'Location details', 'estrategia-web' ), static fn( $args, $block, $attr ) => Location::get_value( $legacy_key( (array) $args ), $block, $attr ), array( 'postId', 'postType' ) ),
		);
		foreach ( $sources as $old => [ $label, $callback, $context ] ) {
			if ( get_block_bindings_source( $old ) ) {
				continue;
			}
			register_block_bindings_source(
				$old,
				array(
					'label'              => $label,
					'get_value_callback' => $callback,
					'uses_context'       => $context,
				)
			);
		}
		global $shortcode_tags;
		foreach ( self::SHORTCODES as $old => $new ) {
			if ( isset( $shortcode_tags[ $new ] ) && ! shortcode_exists( $old ) ) {
				add_shortcode( $old, $shortcode_tags[ $new ] );
			}
		}
	}

	/**
	 * Un bloque leído con nombres viejos, con los nuevos (él y sus hijos).
	 *
	 * @param array $parsed Bloque de parse_blocks().
	 */
	public static function rename( array $parsed ): array {
		if ( isset( self::BLOCKS[ $parsed['blockName'] ?? '' ] ) ) {
			$parsed['blockName'] = self::BLOCKS[ $parsed['blockName'] ];
			$swap                = static fn( $html ) => is_string( $html ) ? strtr( $html, self::CLASSES ) : $html;
			$parsed['innerHTML']    = $swap( $parsed['innerHTML'] ?? '' );
			$parsed['innerContent'] = array_map( $swap, $parsed['innerContent'] ?? array() );
		}
		$parsed['innerBlocks'] = array_map( array( self::class, 'rename' ), $parsed['innerBlocks'] ?? array() );
		return $parsed;
	}

	/**
	 * La opción vieja mientras no exista la nueva.
	 *
	 * @param mixed $default Valor por defecto de get_option().
	 */
	public static function legacy_option( $default ) {
		$legacy = get_option( self::LEGACY_OPTION, null );
		return null === $legacy ? $default : $legacy;
	}
}
