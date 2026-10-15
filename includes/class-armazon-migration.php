<?php
/**
 * EL MIGRADOR de «Armazón» a «Estrategia Web»: pasa una web hecha con el tema
 * padre Armazón y los plugins armazon-site / armazon-forms a la estructura de
 * la 1.1.0 (tema padre estrategia-web + el plugin ÚNICO estrategia-web), EN
 * SITIO, sin volver a cargar contenido. Los nombres públicos (bloques,
 * opciones, metas, tablas) son los de la 1.0.0; lo que cambió en la 1.1.0 es
 * solo la carpeta del plugin, así que una web que ya estaba en la 1.0.0
 * (estrategia-web-site + estrategia-web-forms) también pasa por aquí: solo
 * le toca el paso 7.
 *
 * Qué hace, en este orden (cada paso dice qué cambiaría y cuántos):
 *   1. contenido  post_content de todas las entradas (páginas, entradas,
 *                 patrones sincronizados wp_block, wp_template,
 *                 wp_template_part, wp_navigation, wp_global_styles, los tipos
 *                 del plugin y sus traducciones de WPML, que son entradas):
 *                 bloques armazon/…, armazon-site/… y armazon-forms/… (y
 *                 armazon/consent → estrategia-web-site/consent), sus clases,
 *                 fuentes de bindings, shortcodes, atributos data-armazon-…,
 *                 "theme":"armazon" de las partes y las claves de metas
 *                 renombradas en los bindings core/post-meta. Las revisiones
 *                 NO: son historia (si se restaura una, pintan los alias).
 *   2. metas      las de los tipos client, testimonial y location
 *                 (location_static_map… → estrategia_web_location_static_map…).
 *   3. opciones   armazon_* → estrategia_web_* (datos de la empresa,
 *                 formularios, WhatsApp…), theme_mods_armazon →
 *                 theme_mods_estrategia-web, y dentro de cualquier otra opción
 *                 que nombre algo viejo (WPML guarda ajustes por tema y por
 *                 plugin) los textos y claves que son nombres viejos. Los
 *                 transitorios viejos se borran (son caché).
 *   4. tablas     {prefijo}armazon_forms_submissions y …_definitions →
 *                 estrategia_web_forms_…: ALTER TABLE … RENAME TO, los envíos no
 *                 se tocan. (No «RENAME TABLE»: el SQLite de Playground no lo
 *                 entiende y fallaba EN SILENCIO; ALTER TABLE vale en los dos.
 *                 Después se mira que la nueva exista: si no, aviso.)
 *                 Si la nueva ya existe (alguien activó el plugin nuevo antes)
 *                 y está vacía, se copian las filas y la vieja se deja.
 *   5. wpml       cadenas de String Translation con contexto o nombre viejo
 *                 (armazon-forms, [armazon_forms_settings]…), recalculando su
 *                 domain_name_context_md5; sus traducciones cuelgan del id y
 *                 no se pierden.
 *   6. tema       template/stylesheet armazon → estrategia-web y el término
 *                 wp_theme «armazon» de las plantillas personalizadas.
 *   7. plugins    active_plugins: armazon-site/armazon-site.php,
 *                 armazon-forms/armazon-forms.php (y los dos de la 1.0.0,
 *                 estrategia-web-site/… y estrategia-web-forms/…) salen de la
 *                 lista y entra UNO: estrategia-web/estrategia-web.php. Sin
 *                 llamar a ganchos de activación ni de desactivación (las
 *                 opciones y tablas ya están migradas; las tablas de
 *                 formularios y su cron los rehace el módulo forms en cada
 *                 carga si faltan); el evento de cron viejo se quita. Las
 *                 CARPETAS viejas no se tocan: se borran a mano (o con el
 *                 desplegador) cuando la web ya responde con el plugin nuevo.
 *                 La lista de módulos (estrategia_web_modules) NO se escribe:
 *                 sin ella valen los «Default» de cada módulo.
 *   8. caché      la de objetos y, si está, la de SiteGround.
 *   La cookie del consentimiento NO se migra aquí (vive en el navegador): la
 *   pasa blocks/consent/view.js la primera vez que vuelve cada visitante.
 *
 * Cómo se usa:
 *   - WP-CLI, con el plugin nuevo activo: wp estrategia-web migrate-armazon [--dry-run]
 *   - Sin activar nada (lo que hace Lavandería): incluir este fichero desde
 *     «wp eval-file» y llamar a Armazon_Migration::run( $seco ). Ver
 *     lavanderia-mallorca/despliegue/migrar-a-estrategia-web.mjs.
 *   NO corre solo al activar el plugin, a propósito: renombra tablas y
 *   reescribe todo el contenido, y eso se hace con copia de seguridad y
 *   habiendo mirado antes el seco.
 *
 * IDEMPOTENTE: cada paso busca lo viejo; pasarlo dos veces no cambia nada la
 * segunda (lo comprueba la prueba de Lavandería). En seco no escribe nada.
 * Los pasos 6 y 7 se saltan si las carpetas nuevas (themes/estrategia-web,
 * plugins/estrategia-web) no están en el servidor: primero se suben los
 * paquetes.
 *
 * Cómo se mantiene:
 * - Quién: quien toque el cambio de nombre. Cuándo: si aparece otro sitio
 *   donde se guardaba un nombre viejo, se añade aquí y a su prueba.
 * - Se quita en 2.0.0, con Legacy_Names, cuando todas las webs que usaron
 *   Armazón lo hayan pasado (07-10-2026: solo Lavandería Mallorca).
 * - Caso frontera: un texto de la web que diga «armazon-» (no «armazón»).
 *   Se renombraría. Solo se tocan tokens pegados a «-», «_» o «/», que en
 *   texto normal no aparecen; el seco los lista por entrada para mirarlos.
 * - La trampa: WordPress guarda los theme_mods POR CARPETA del tema. Si el
 *   padre estaba activo él solo (sin hijo), sus ajustes viven en
 *   theme_mods_armazon y sin el paso 3 se perderían al cambiar de carpeta.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Armazon_Migration {

	public const THEME = array( 'armazon' => 'estrategia-web' );

	/** El plugin único (1.1.0). Todos los de antes acaban en él. */
	public const PLUGIN = 'estrategia-web/estrategia-web.php';

	public const PLUGINS = array(
		'armazon-site/armazon-site.php'                 => self::PLUGIN,
		'armazon-forms/armazon-forms.php'               => self::PLUGIN,
		'estrategia-web-site/estrategia-web-site.php'   => self::PLUGIN,
		'estrategia-web-forms/estrategia-web-forms.php' => self::PLUGIN,
	);

	/** Metas de los tipos del plugin que ganaron prefijo en 1.0.0. */
	public const META = array( 'client_image_fit', 'testimonial_author', 'testimonial_rating', 'location_address', 'location_phone', 'location_email', 'location_schedule', 'location_map_url', 'location_map_embed', 'location_static_map', 'location_static_map_id' );

	/** Claves que solo existen en los bindings (derivadas, no metas). */
	public const BINDING_KEYS = array( 'location_phone_href', 'location_email_href' );

	public const META_TYPES = array( 'client', 'testimonial', 'location' );

	public const TABLES = array( 'armazon_forms_submissions' => 'estrategia_web_forms_submissions', 'armazon_forms_definitions' => 'estrategia_web_forms_definitions' );

	public const OLD_CRON = array( 'armazon_forms_purge' );

	/** Opciones que no pasan por el renombrado genérico (tienen paso propio). */
	private const OWN_STEP = array( 'active_plugins', 'template', 'stylesheet', 'theme_mods_armazon', 'rewrite_rules', 'cron' );

	private static bool $dry = true;

	/** @var array<string, mixed> */
	private static array $report = array();

	/**
	 * Corre la migración. Devuelve lo hecho (o lo que haría, en seco).
	 *
	 * @return array<string, mixed>
	 */
	public static function run( bool $dry_run ): array {
		self::$dry    = $dry_run;
		self::$report = array(
			'seco'      => $dry_run,
			'contenido' => array(),
			'metas'     => array(),
			'opciones'  => array(),
			'tablas'    => array(),
			'wpml'      => array(),
			'tema'      => array(),
			'plugins'   => array(),
			'avisos'    => array(),
		);
		self::content();
		self::metas();
		self::options();
		self::tables();
		self::wpml();
		self::theme();
		self::plugins();
		self::cache();
		return self::$report;
	}

	// ------------------------------------------------------------ el renombrado

	/**
	 * Un marcado con los nombres nuevos.
	 *
	 * @param array{bloques: int, otros: int} $count Cuenta, por referencia.
	 */
	public static function rename_markup( string $html, ?array &$count = null ): string {
		$count = array( 'bloques' => 0, 'otros' => 0 );
		// 1. Nombres de bloque en los comentarios (los de apertura cuentan como bloques).
		$html = preg_replace_callback(
			'#<!--(\s+)(/?)wp:(armazon(?:-site|-forms)?)/([a-z0-9-]+)#',
			static function ( $m ) use ( &$count ) {
				if ( '' === $m[2] ) {
					++$count['bloques'];
				}
				return '<!--' . $m[1] . $m[2] . 'wp:' . self::block_name( $m[3] . '/' . $m[4] );
			},
			$html
		);
		// 2. La clase que WordPress deriva del alias viejo del enlace de cookies.
		$html = str_replace( 'wp-block-armazon-consent', 'wp-block-estrategia-web-site-consent', $html, $n );
		$count['otros'] += $n;
		// 3. Claves de metas renombradas, solo como clave de un binding.
		$keys = implode( '|', array_merge( self::META, self::BINDING_KEYS ) );
		$html = preg_replace( '#("key"\s*:\s*")(' . $keys . ')"#', '$1estrategia_web_$2"', $html, -1, $n );
		$count['otros'] += $n;
		// 4. Lo demás: tokens pegados a «-», «_» o «/» y el tema de las partes.
		$html = preg_replace( '#"theme"\s*:\s*"armazon"#', '"theme":"estrategia-web"', $html, -1, $n );
		$count['otros'] += $n;
		$html = preg_replace_callback(
			'#(?<![\w-])armazon([-_/:])#',
			static fn( $m ) => ( '_' === $m[1] ? 'estrategia_web' : 'estrategia-web' ) . $m[1],
			$html,
			-1,
			$n
		);
		$count['otros'] += $n;
		return $html;
	}

	/** Nombre de bloque viejo → nuevo. */
	public static function block_name( string $old ): string {
		if ( 'armazon/consent' === $old ) {
			return 'estrategia-web-site/consent';
		}
		return preg_replace( '#^armazon(-site|-forms)?/#', 'estrategia-web$1/', $old );
	}

	/**
	 * Cualquier valor (opción deserializada) con los nombres nuevos, claves incluidas.
	 *
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	public static function rename_value( $value ) {
		if ( is_string( $value ) ) {
			if ( isset( self::THEME[ $value ] ) ) {
				return self::THEME[ $value ];
			}
			if ( isset( self::PLUGINS[ $value ] ) ) {
				return self::PLUGINS[ $value ];
			}
			return self::rename_markup( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? self::rename_value( $key ) : $key ] = self::rename_value( $item );
			}
			return $out;
		}
		if ( is_object( $value ) && ! $value instanceof \__PHP_Incomplete_Class ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->$key = self::rename_value( $item );
			}
		}
		return $value;
	}

	// ------------------------------------------------------------------- pasos

	private static function content(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_type, post_name, post_status, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND post_content LIKE '%armazon%' ORDER BY ID", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$new = self::rename_markup( $row['post_content'], $count );
			if ( $new === $row['post_content'] ) {
				continue;
			}
			$lang                            = apply_filters( 'wpml_post_language_details', null, (int) $row['ID'] );
			self::$report['contenido'][] = array(
				'id'      => (int) $row['ID'],
				'tipo'    => $row['post_type'],
				'slug'    => $row['post_name'],
				'estado'  => $row['post_status'],
				'idioma'  => is_array( $lang ) ? ( $lang['language_code'] ?? '' ) : '',
				'bloques' => $count['bloques'],
				'otros'   => $count['otros'],
			);
			if ( ! self::$dry ) {
				// Directo a la tabla: wp_update_post() crearía una revisión y pasaría
				// por kses y por los ganchos de guardado (WPML, Rank Math) por nada.
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $row['ID'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( (int) $row['ID'] );
			}
		}
	}

	private static function metas(): void {
		global $wpdb;
		// Con y sin el prefijo de la 1.2.0 (Type_Names): da igual cuál de los dos migradores pase antes.
		$all   = self::META_TYPES;
		if ( class_exists( Type_Names::class ) ) {
			$all = array_merge( $all, array_map( array( Type_Names::class, 'name' ), self::META_TYPES ) );
		}
		$types = "'" . implode( "','", $all ) . "'";
		foreach ( self::META as $key ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type IN ($types)", $key ) ); // phpcs:ignore
			if ( ! $n ) {
				continue;
			}
			self::$report['metas'][] = sprintf( '%s → estrategia_web_%s: %d', $key, $key, $n );
			if ( ! self::$dry ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id SET m.meta_key = %s WHERE m.meta_key = %s AND p.post_type IN ($types)", 'estrategia_web_' . $key, $key ) ); // phpcs:ignore
			}
		}
		if ( ! self::$dry && self::$report['metas'] ) {
			wp_cache_flush_group( 'post_meta' );
		}
	}

	private static function options(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%armazon%' OR option_value LIKE '%armazon%'", ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$name = $row['option_name'];
			if ( in_array( $name, self::OWN_STEP, true ) || str_ends_with( $name, 'theme_roots' ) ) {
				continue;
			}
			// theme_roots no: lista las carpetas de themes/, y la vieja sigue allí hasta borrarla a mano.
			if ( preg_match( '#^_(site_)?transient_#', $name ) && ! str_ends_with( $name, 'theme_roots' ) ) {
				// Cachés (de WPML, de los patrones del tema…) que nombran algo viejo:
				// se borran y quien las hizo las rehace con los nombres nuevos.
				self::$report['opciones'][] = "$name: se borra (caché)";
				if ( ! self::$dry ) {
					$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore
				}
				continue;
			}
			$value    = maybe_unserialize( $row['option_value'] );
			$new_name = preg_match( '#^armazon_#', $name ) ? 'estrategia_web_' . substr( $name, 8 ) : $name;
			$new      = self::rename_value( $value );
			if ( $new_name !== $name ) {
				if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $new_name ) ) ) { // phpcs:ignore
					self::$report['avisos'][] = "$name: ya existe $new_name; no se pisa (la vieja se queda)";
					continue;
				}
				self::$report['opciones'][] = "$name → $new_name";
				if ( ! self::$dry ) {
					$wpdb->update( $wpdb->options, array( 'option_name' => $new_name, 'option_value' => maybe_serialize( $new ) ), array( 'option_name' => $name ) ); // phpcs:ignore
				}
				continue;
			}
			if ( $new !== $value ) {
				self::$report['opciones'][] = "$name: nombres viejos dentro del valor";
				if ( ! self::$dry ) {
					$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $new ) ), array( 'option_name' => $name ) ); // phpcs:ignore
				}
			}
		}
		// theme_mods: se guardan por carpeta del tema (ver la trampa de la cabecera).
		$mods = get_option( 'theme_mods_armazon', null );
		if ( null !== $mods ) {
			if ( null !== get_option( 'theme_mods_estrategia-web', null ) ) {
				self::$report['avisos'][] = 'theme_mods_armazon: ya existe theme_mods_estrategia-web; no se pisa';
			} else {
				self::$report['opciones'][] = 'theme_mods_armazon → theme_mods_estrategia-web';
				if ( ! self::$dry ) {
					update_option( 'theme_mods_estrategia-web', self::rename_value( $mods ) );
					delete_option( 'theme_mods_armazon' );
				}
			}
		}
		if ( ! self::$dry ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	private static function tables(): void {
		global $wpdb;
		foreach ( self::TABLES as $old => $new ) {
			$old_t = $wpdb->prefix . $old;
			$new_t = $wpdb->prefix . $new;
			$has   = static fn( $t ) => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
			if ( ! $has( $old_t ) ) {
				continue;
			}
			$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$old_t`" ); // phpcs:ignore
			if ( ! $has( $new_t ) ) {
				self::$report['tablas'][] = "$old_t → $new_t (se renombra, $rows filas)";
				if ( ! self::$dry ) {
					$wpdb->query( "ALTER TABLE `$old_t` RENAME TO `$new_t`" ); // phpcs:ignore
					if ( ! $has( $new_t ) && self::create_forms_tables() && $has( $new_t ) ) {
						// Plan B (el SQLite de Playground no renombra tablas): crearlas con
						// la migración del plugin nuevo y copiar las filas. La vieja se queda.
						$wpdb->query( "INSERT INTO `$new_t` SELECT * FROM `$old_t`" ); // phpcs:ignore
						self::$report['avisos'][] = "$old_t: no se pudo renombrar; se ha creado $new_t y se han COPIADO las filas (la vieja se queda: bórrala a mano cuando se haya comprobado)";
					}
					if ( ! $has( $new_t ) || $rows !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$new_t`" ) ) { // phpcs:ignore
						self::$report['avisos'][] = "$old_t: NO se ha renombrado ni copiado ({$wpdb->last_error})";
					}
				}
				continue;
			}
			$present = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$new_t`" ); // phpcs:ignore
			if ( $present === $rows ) {
				// Ya copiada (el plan B de una pasada anterior deja la vieja): nada que hacer.
				// Idempotencia; la vieja se borra a mano, como dice el aviso de aquella pasada.
				continue;
			}
			if ( $present ) {
				self::$report['avisos'][] = "$old_t ($rows filas) y $new_t ($present filas) existen las dos y la nueva no está vacía: no se toca ninguna, se mira a mano";
				continue;
			}
			self::$report['tablas'][] = "$old_t → $new_t (la nueva existía vacía: se copian $rows filas; la vieja se queda)";
			if ( ! self::$dry ) {
				$wpdb->query( "INSERT INTO `$new_t` SELECT * FROM `$old_t`" ); // phpcs:ignore
			}
		}
	}

	/** Crea las tablas nuevas con la migración del plugin de formularios nuevo (plan B de tables()). */
	private static function create_forms_tables(): bool {
		$dir = WP_PLUGIN_DIR . '/' . dirname( self::PLUGIN ) . '/modules/forms/includes/';
		if ( ! is_file( $dir . 'migrations/0001-create-tables.php' ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! class_exists( '\EstrategiaWeb\Forms\Migrations' ) ) {
			require_once $dir . 'class-migrations.php';
		}
		global $wpdb;
		$create = require $dir . 'migrations/0001-create-tables.php';
		$create( $wpdb );
		return true;
	}

	private static function wpml(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_strings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$rows = $wpdb->get_results( "SELECT id, context, name, gettext_context FROM `$table` WHERE context LIKE '%armazon%' OR name LIKE '%armazon%'", ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$context = self::rename_value( $row['context'] );
			$name    = self::rename_value( $row['name'] );
			if ( $context === $row['context'] && $name === $row['name'] ) {
				continue;
			}
			self::$report['wpml'][] = sprintf( '#%d %s / %s → %s / %s', $row['id'], $row['context'], $row['name'], $context, $name );
			if ( ! self::$dry ) {
				$wpdb->update(
					$table,
					array(
						'context'                 => $context,
						'name'                    => $name,
						'domain_name_context_md5' => md5( $context . $name . $row['gettext_context'] ),
					),
					array( 'id' => (int) $row['id'] )
				); // phpcs:ignore
			}
		}
	}

	private static function theme(): void {
		global $wpdb;
		$ready = is_file( get_theme_root() . '/estrategia-web/style.css' );
		foreach ( array( 'template', 'stylesheet' ) as $option ) {
			if ( 'armazon' !== get_option( $option ) ) {
				continue;
			}
			self::$report['tema'][] = "$option: armazon → estrategia-web" . ( $ready ? '' : ' (FALTA themes/estrategia-web: no se cambia)' );
			if ( ! self::$dry && $ready ) {
				update_option( $option, 'estrategia-web' );
			}
		}
		$term = get_term_by( 'slug', 'armazon', 'wp_theme' );
		if ( $term ) {
			self::$report['tema'][] = "término wp_theme armazon (#{$term->term_id}) → estrategia-web";
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->terms, array( 'slug' => 'estrategia-web', 'name' => 'estrategia-web' ), array( 'term_id' => $term->term_id ) ); // phpcs:ignore
				clean_term_cache( $term->term_id, 'wp_theme' );
			}
		}
	}

	private static function plugins(): void {
		$active  = (array) get_option( 'active_plugins', array() );
		$changed = $active;
		foreach ( self::PLUGINS as $old => $new ) {
			$at = array_search( $old, $changed, true );
			if ( false === $at ) {
				continue;
			}
			$ready = is_file( WP_PLUGIN_DIR . '/' . $new );
			self::$report['plugins'][] = "$old → $new" . ( $ready ? '' : ' (FALTA el plugin nuevo: no se cambia)' );
			if ( $ready ) {
				$changed[ $at ] = $new;
			}
		}
		foreach ( self::OLD_CRON as $hook ) {
			if ( wp_next_scheduled( $hook ) ) {
				self::$report['plugins'][] = "cron $hook: se quita (el plugin nuevo programa el suyo)";
				if ( ! self::$dry ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		}
		if ( ! self::$dry && $changed !== $active ) {
			update_option( 'active_plugins', array_values( array_unique( $changed ) ) );
			delete_option( 'rewrite_rules' ); // los tipos del plugin se vuelven a registrar
		}
	}

	private static function cache(): void {
		if ( self::$dry ) {
			return;
		}
		wp_cache_flush();
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'estrategia-web migrate-armazon',
		static function ( $args, $assoc ) {
			$report = Armazon_Migration::run( ! empty( $assoc['dry-run'] ) );
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		},
		array( 'shortdesc' => 'Pasa una web de Armazón (o de la 1.0.0) a Estrategia Web 1.1.0 (contenido, metas, opciones, tablas, WPML, tema y el plugin único). Con --dry-run solo lo cuenta.' )
	);
}
