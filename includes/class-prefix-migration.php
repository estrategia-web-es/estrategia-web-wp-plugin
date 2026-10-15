<?php
/**
 * EL MIGRADOR DEL PREFIJO (1.2.0): los tipos y taxonomías del módulo site
 * pasan de «client», «client_sector»… a «ew_client», «ew_client_sector»…
 * (el mapa entero sale de Type_Names, modules/site/includes/class-type-names.php:
 * el prefijo vive SOLO allí). EN SITIO y sin volver a cargar contenido.
 * Las URL públicas no cambian (salen del slug, no del nombre).
 *
 * Qué hace, en este orden (cada paso dice qué cambiaría y cuántos):
 *   1. tipos       wp_posts.post_type, todas las filas (también borradores y
 *                  papelera; las revisiones son «revision» y no se tocan).
 *   2. taxonomías  wp_term_taxonomy.taxonomy. Los term_id NO cambian.
 *   3. contenido   post_content de todo menos revisiones y adjuntos, SOLO
 *                  dentro del comentario de un bloque: core/query
 *                  ("postType" y las claves de "taxQuery", con o sin
 *                  "include"/"exclude"), core/post-terms ("term") y
 *                  core/navigation-link/-submenu ("type"). Directo a la tabla,
 *                  sin revisión ni ganchos de guardado. Por cada entrada
 *                  cambiada dispara estrategia_web_prefix_migration_post
 *                  (id, antes, después, seco): así una web que guarde huellas
 *                  de su carga (Lavandería: despliegue/prefijo-tipos.php) las
 *                  renueva y el cambio NO cuenta como edición del cliente.
 *   4. plantillas  wp_template / wp_template_part llamadas single-client,
 *                  archive-client, taxonomy-client_sector(-slug)…
 *   5. menús       los elementos de menú clásico que apuntan a un tipo, a su
 *                  archivo o a una taxonomía (_menu_item_object).
 *   6. wpml        icl_translations (element_type post_client → post_ew_client,
 *                  tax_client_sector → tax_ew_client_sector), los ajustes de
 *                  tipos y taxonomías traducibles de icl_sitepress_settings
 *                  (SETTINGS_SECTIONS, solo CLAVES y solo en esas secciones) y
 *                  las cadenas de traducción de slugs («URL slug: client»).
 *   7. rank math   las opciones rank-math-options-* por tipo y taxonomía
 *                  (pt_client_… / tax_client_sector_… y el valor de
 *                  pt_…_primary_taxonomy) y la meta rank_math_primary_<tax>.
 *   8. pantallas   las preferencias por tipo de cada usuario (columnas
 *                  ocultas, entradas por página, cajas cerradas): cosmético.
 *   9. avisos      lo que NO sabe migrar y nombra un tipo viejo: opciones con
 *                  el nombre como cadena serializada. Se listan para mirarlas
 *                  a mano; no se tocan.
 *  10. permalinks  rewrite_rules se borra (WordPress las rehace) y la caché.
 * La opción estrategia_web_site NO cambia: enabled_types y rewrite_slugs
 * hablan en CLAVES (ver la cabecera de Type_Names).
 *
 * Cómo se usa:
 *   - WP-CLI, con el plugin 1.2.0 activo: wp estrategia-web migrate-prefix [--dry-run]
 *   - Sin WP-CLI propio (Lavandería): incluir este fichero y class-type-names.php
 *     desde «wp eval-file» y llamar a Prefix_Migration::run( $seco ). Ver
 *     lavanderia-mallorca/despliegue/prefijo-tipos.php, que además renueva
 *     las huellas de la carga.
 *   NO corre solo al activar ni al actualizar: se pasa con copia de
 *   seguridad y habiendo mirado antes el seco. Mientras no se pasa, la web
 *   con el plugin 1.2.0 no encuentra sus clientes, opiniones ni sedes (el
 *   desplegador lo pasa justo después de subir el plugin).
 *
 * IDEMPOTENTE: cada paso busca lo viejo; la segunda pasada no cambia nada.
 * En seco no escribe nada.
 *
 * Cómo se mantiene:
 * - Quién: quien toque los tipos. Cuándo: si aparece otro sitio donde se
 *   guarda un nombre de tipo (lo dirá el paso 9 en el seco de una web), se
 *   añade como paso y a su prueba (modules/site/tests/prefix-migration.test.mjs).
 * - Se quita en 2.0.0, con Unprefixed_Names, cuando todas las webs lo hayan
 *   pasado (15-10-2026: solo Lavandería Mallorca).
 * - Caso frontera, con nombre: OTRO PLUGIN CON UN TIPO «client». Si al migrar
 *   de verdad el nombre viejo sigue registrado (no por nosotros: el plugin
 *   1.2.0 ya no lo registra), sus filas son de ese plugin: el tipo o la
 *   taxonomía se salta y sale un aviso. En seco no se mira, porque allí corre
 *   aún el plugin viejo, que sí lo registra.
 * - Caso frontera: el contenido de una entrada que diga «client» en el texto.
 *   No se toca: solo se cambia dentro del JSON del comentario de los cuatro
 *   bloques, y solo en las claves dichas.
 * - La trampa: WPML guarda sus ajustes como un array grande con claves que
 *   pueden llamarse igual que un tipo en otra sección (p. ej. «location» en
 *   la del selector de idioma). Por eso solo se renombran claves dentro de
 *   SETTINGS_SECTIONS, y nunca valores.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Type_Names::class ) ) {
	require_once dirname( __DIR__ ) . '/modules/site/includes/class-type-names.php';
}

final class Prefix_Migration {

	/** Secciones de icl_sitepress_settings cuyas CLAVES son tipos o taxonomías. */
	public const SETTINGS_SECTIONS = array(
		'custom_posts_sync_option',
		'custom_posts_unlocked_option',
		'taxonomies_sync_option',
		'taxonomies_unlocked_option',
	);

	/** Bloques y atributos de su JSON que guardan un nombre de tipo o taxonomía. */
	private const BLOCK_KEYS = array(
		'query'              => array( 'postType' ),
		'post-terms'         => array( 'term' ),
		'navigation-link'    => array( 'type' ),
		'navigation-submenu' => array( 'type' ),
	);

	/** Preferencias de pantalla por tipo (usermeta); %s = el tipo. */
	private const USER_META = array( 'edit_%s_per_page', 'manageedit-%scolumnshidden', 'meta-box-order_%s', 'closedpostboxes_%s', 'metaboxhidden_%s', 'screen_layout_%s' );

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
			'seco'       => $dry_run,
			'prefijo'    => Type_Names::PREFIX,
			'tipos'      => array(),
			'taxonomias' => array(),
			'contenido'  => array(),
			'plantillas' => array(),
			'menus'      => array(),
			'wpml'       => array(),
			'rankmath'   => array(),
			'pantallas'  => array(),
			'avisos'     => array(),
		);
		$types = self::types();
		$taxes = self::taxonomies();
		self::content( $types + $taxes );
		self::templates( $types, $taxes );
		self::menus( $types + $taxes );
		self::wpml( $types, $taxes );
		self::rank_math( $types, $taxes );
		self::screens( $types );
		self::leftovers( $types + $taxes );
		if ( ! self::$dry ) {
			delete_option( 'rewrite_rules' );
			wp_cache_flush();
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
			}
		}
		return self::$report;
	}

	// ------------------------------------------------------------ el renombrado

	/**
	 * Un marcado de bloques con los nombres nuevos, solo dentro del JSON de
	 * los bloques de BLOCK_KEYS.
	 *
	 * @param array<string,string> $map   Viejo → nuevo.
	 * @param int|null             $count Cambios hechos, por referencia.
	 */
	public static function rename_markup( string $html, array $map, ?int &$count = null ): string {
		$count = 0;
		if ( ! $map || ! str_contains( $html, '<!-- wp:' ) ) {
			return $html;
		}
		$names = implode( '|', array_map( 'preg_quote', array_keys( $map ) ) );
		return (string) preg_replace_callback(
			'#<!--\s+wp:(?:core/)?(query|post-terms|navigation-link|navigation-submenu)\s+(\{.*?\})\s+(/?)-->#s',
			static function ( $m ) use ( $map, $names, &$count ) {
				$json = $m[2];
				foreach ( self::BLOCK_KEYS[ $m[1] ] as $attr ) {
					$json = preg_replace_callback(
						'#("' . $attr . '"\s*:\s*")(' . $names . ')"#',
						static function ( $k ) use ( $map, &$count ) {
							++$count;
							return $k[1] . $map[ $k[2] ] . '"';
						},
						$json
					);
				}
				if ( 'query' === $m[1] ) {
					// Las claves de taxQuery: "client_sector":[…] (también dentro de "include"/"exclude").
					$json = preg_replace_callback(
						'#("taxQuery"\s*:\s*\{)(.*?)(\}\s*[,}])#s',
						static function ( $t ) use ( $map, $names, &$count ) {
							$inner = preg_replace_callback(
								'#"(' . $names . ')"(\s*:\s*\[)#',
								static function ( $k ) use ( $map, &$count ) {
									++$count;
									return '"' . $map[ $k[1] ] . '"' . $k[2];
								},
								$t[2]
							);
							return $t[1] . $inner . $t[3];
						},
						$json
					);
				}
				return str_replace( $m[2], $json, $m[0] );
			},
			$html
		);
	}

	// ------------------------------------------------------------------- pasos

	/** @return array<string,string> Los tipos que se migran (viejo → nuevo). */
	private static function types(): array {
		global $wpdb;
		$done = array();
		foreach ( Type_Names::renamed_types() as $old => $new ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $n ) {
				$done[ $old ] = $new; // Nada en wp_posts, pero puede quedar en ajustes (WPML, Rank Math).
				continue;
			}
			if ( ! self::$dry && post_type_exists( $old ) ) {
				self::$report['avisos'][] = "tipo $old: hay $n entradas pero «$old» lo registra otro plugin; no se toca";
				continue;
			}
			self::$report['tipos'][] = "$old → $new: $n entradas";
			$done[ $old ]            = $new;
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->posts, array( 'post_type' => $new ), array( 'post_type' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		return $done;
	}

	/** @return array<string,string> Las taxonomías que se migran (viejo → nuevo). */
	private static function taxonomies(): array {
		global $wpdb;
		$done = array();
		foreach ( Type_Names::renamed_taxonomies() as $old => $new ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $n ) {
				$done[ $old ] = $new;
				continue;
			}
			if ( ! self::$dry && taxonomy_exists( $old ) ) {
				self::$report['avisos'][] = "taxonomía $old: hay $n términos pero «$old» la registra otro plugin; no se toca";
				continue;
			}
			self::$report['taxonomias'][] = "$old → $new: $n términos";
			$done[ $old ]                 = $new;
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $new ), array( 'taxonomy' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		if ( ! self::$dry ) {
			wp_cache_flush_group( 'terms' );
			wp_cache_delete( 'last_changed', 'terms' );
		}
		return $done;
	}

	/** @param array<string,string> $map */
	private static function content( array $map ): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_type, post_name, post_status, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND ( post_content LIKE '%wp:query%' OR post_content LIKE '%wp:post-terms%' OR post_content LIKE '%wp:navigation-%' ) ORDER BY ID", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$new = self::rename_markup( $row['post_content'], $map, $count );
			if ( $new === $row['post_content'] ) {
				continue;
			}
			$lang                        = apply_filters( 'wpml_post_language_details', null, (int) $row['ID'] );
			$entry                       = array(
				'id'      => (int) $row['ID'],
				'tipo'    => $row['post_type'],
				'slug'    => $row['post_name'],
				'estado'  => $row['post_status'],
				'idioma'  => is_array( $lang ) ? ( $lang['language_code'] ?? '' ) : '',
				'cambios' => $count,
			);
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $row['ID'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( (int) $row['ID'] );
			}
			/**
			 * Una entrada cuyo contenido cambia el migrador (también en seco, sin
			 * escribir). Lo que devuelva el gancho en 'nota' sale en el informe.
			 *
			 * @param array  $entry Lo que sale en el informe (id, tipo, slug…).
			 * @param string $old   El contenido de antes.
			 * @param string $new   El de después.
			 * @param bool   $dry   Seco.
			 */
			self::$report['contenido'][] = apply_filters( 'estrategia_web_prefix_migration_post', $entry, $row['post_content'], $new, self::$dry );
		}
	}

	/**
	 * @param array<string,string> $types
	 * @param array<string,string> $taxes
	 */
	private static function templates( array $types, array $taxes ): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_type, post_name FROM {$wpdb->posts} WHERE post_type IN ('wp_template','wp_template_part') AND ( post_name LIKE 'single-%' OR post_name LIKE 'archive-%' OR post_name LIKE 'taxonomy-%' )", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$new = $row['post_name'];
			foreach ( array( 'single' => $types, 'archive' => $types, 'taxonomy' => $taxes ) as $kind => $map ) {
				foreach ( $map as $old => $to ) {
					if ( preg_match( '#^' . $kind . '-' . preg_quote( $old, '#' ) . '(-.*)?$#', $row['post_name'], $m ) ) {
						$new = $kind . '-' . $to . ( $m[1] ?? '' );
					}
				}
			}
			if ( $new === $row['post_name'] ) {
				continue;
			}
			self::$report['plantillas'][] = "#{$row['ID']} {$row['post_type']} {$row['post_name']} → $new";
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->posts, array( 'post_name' => $new ), array( 'ID' => (int) $row['ID'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( (int) $row['ID'] );
			}
		}
	}

	/** @param array<string,string> $map */
	private static function menus( array $map ): void {
		global $wpdb;
		foreach ( $map as $old => $new ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_object' AND meta_value = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $ids ) {
				continue;
			}
			self::$report['menus'][] = "_menu_item_object $old → $new: " . count( $ids ) . ' elementos de menú';
			if ( ! self::$dry ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_menu_item_object' AND meta_value = %s", $new, $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				wp_cache_flush_group( 'post_meta' );
			}
		}
	}

	/**
	 * @param array<string,string> $types
	 * @param array<string,string> $taxes
	 */
	private static function wpml( array $types, array $taxes ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			foreach ( array( 'post_' => $types, 'tax_' => $taxes ) as $kind => $map ) {
				foreach ( $map as $old => $new ) {
					$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE element_type = %s", $kind . $old ) ); // phpcs:ignore
					if ( ! $n ) {
						continue;
					}
					self::$report['wpml'][] = "icl_translations {$kind}{$old} → {$kind}{$new}: $n filas";
					if ( ! self::$dry ) {
						$wpdb->update( $table, array( 'element_type' => $kind . $new ), array( 'element_type' => $kind . $old ) ); // phpcs:ignore
					}
				}
			}
		}
		$settings = get_option( 'icl_sitepress_settings', null );
		if ( is_array( $settings ) ) {
			$changed = $settings;
			foreach ( self::SETTINGS_SECTIONS as $section ) {
				if ( empty( $settings[ $section ] ) || ! is_array( $settings[ $section ] ) ) {
					continue;
				}
				$map = str_starts_with( $section, 'taxonomies' ) ? $taxes : $types;
				$out = array();
				foreach ( $settings[ $section ] as $key => $value ) {
					if ( isset( $map[ $key ] ) ) {
						if ( array_key_exists( $map[ $key ], $settings[ $section ] ) ) {
							self::$report['avisos'][] = "WPML $section: están «{$key}» y «{$map[ $key ]}»; se queda el nuevo";
							continue;
						}
						self::$report['wpml'][] = "icl_sitepress_settings[$section]: $key → {$map[ $key ]} (" . wp_json_encode( $value ) . ')';
						$out[ $map[ $key ] ]    = $value;
						continue;
					}
					$out[ $key ] = $value;
				}
				$changed[ $section ] = $out;
			}
			if ( $changed !== $settings && ! self::$dry ) {
				update_option( 'icl_sitepress_settings', $changed );
			}
		}
		// Traducción de slugs de WPML: cadenas «URL slug: client» del contexto WordPress.
		$strings = $wpdb->prefix . 'icl_strings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $strings ) ) === $strings ) {
			foreach ( $types + $taxes as $old => $new ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, context, name, gettext_context FROM `$strings` WHERE name = %s", 'URL slug: ' . $old ), ARRAY_A ); // phpcs:ignore
				foreach ( $rows as $row ) {
					$name                   = 'URL slug: ' . $new;
					self::$report['wpml'][] = "icl_strings #{$row['id']} «{$row['name']}» → «{$name}»";
					if ( ! self::$dry ) {
						$wpdb->update( $strings, array( 'name' => $name, 'domain_name_context_md5' => md5( $row['context'] . $name . $row['gettext_context'] ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore
					}
				}
			}
		}
	}

	/**
	 * @param array<string,string> $types
	 * @param array<string,string> $taxes
	 */
	private static function rank_math( array $types, array $taxes ): void {
		global $wpdb;
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rank-math-options-%'" ); // phpcs:ignore
		foreach ( $names as $option ) {
			$value = get_option( $option );
			if ( ! is_array( $value ) ) {
				continue;
			}
			$out = array();
			$n   = 0;
			foreach ( $value as $key => $item ) {
				$new_key = $key;
				foreach ( array( 'pt_' => $types, 'tax_' => $taxes ) as $kind => $map ) {
					foreach ( $map as $old => $new ) {
						if ( str_starts_with( (string) $key, $kind . $old . '_' ) ) {
							$new_key = $kind . $new . substr( (string) $key, strlen( $kind . $old ) );
						}
					}
				}
				if ( str_ends_with( (string) $new_key, '_primary_taxonomy' ) && is_string( $item ) && isset( $taxes[ $item ] ) ) {
					$item = $taxes[ $item ];
					++$n;
				}
				if ( $new_key !== $key ) {
					if ( array_key_exists( $new_key, $value ) ) {
						continue; // El nuevo ya está: se queda el nuevo.
					}
					++$n;
				}
				$out[ $new_key ] = $item;
			}
			if ( ! $n ) {
				continue;
			}
			self::$report['rankmath'][] = "$option: $n ajustes por tipo o taxonomía";
			if ( ! self::$dry ) {
				update_option( $option, $out );
			}
		}
		foreach ( $taxes as $old => $new ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", 'rank_math_primary_' . $old ) ); // phpcs:ignore
			if ( ! $n ) {
				continue;
			}
			self::$report['rankmath'][] = "meta rank_math_primary_$old → rank_math_primary_$new: $n";
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_key' => 'rank_math_primary_' . $new ), array( 'meta_key' => 'rank_math_primary_' . $old ) ); // phpcs:ignore
				wp_cache_flush_group( 'post_meta' );
			}
		}
	}

	/** @param array<string,string> $types */
	private static function screens( array $types ): void {
		global $wpdb;
		foreach ( $types as $old => $new ) {
			foreach ( self::USER_META as $pattern ) {
				$from = sprintf( $pattern, $old );
				$to   = sprintf( $pattern, $new );
				$n    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $from ) ); // phpcs:ignore
				if ( ! $n ) {
					continue;
				}
				self::$report['pantallas'][] = "$from → $to: $n usuarios";
				if ( ! self::$dry ) {
					$wpdb->update( $wpdb->usermeta, array( 'meta_key' => $to ), array( 'meta_key' => $from ) ); // phpcs:ignore
					wp_cache_flush_group( 'user_meta' );
				}
			}
		}
	}

	/**
	 * Opciones que nombran un tipo viejo como cadena serializada y que este
	 * migrador NO sabe migrar: solo se listan (paso 9).
	 *
	 * @param array<string,string> $map
	 */
	private static function leftovers( array $map ): void {
		global $wpdb;
		// estrategia_web_site habla en claves a propósito (Type_Names): no es un resto.
		$skip  = array( 'rewrite_rules', 'icl_sitepress_settings', 'estrategia_web_site' );
		$like  = array();
		$regex = array();
		foreach ( array_keys( $map ) as $old ) {
			$like[]  = $wpdb->prepare( 'option_value LIKE %s', '%"' . $wpdb->esc_like( $old ) . '"%' );
			$regex[] = preg_quote( $old, '#' );
		}
		if ( ! $like ) {
			return;
		}
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '%transient%' AND option_name NOT LIKE 'rank-math-options-%' AND ( " . implode( ' OR ', $like ) . ' )', ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			if ( in_array( $row['option_name'], $skip, true ) ) {
				continue;
			}
			if ( preg_match_all( '#s:\d+:"(' . implode( '|', $regex ) . ')";#', $row['option_value'], $m ) ) {
				self::$report['avisos'][] = "opción {$row['option_name']}: nombra " . implode( ', ', array_unique( $m[1] ) ) . ' (no se migra; mirarla)';
			}
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'estrategia-web migrate-prefix',
		static function ( $args, $assoc ) {
			$report = Prefix_Migration::run( ! empty( $assoc['dry-run'] ) );
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		},
		array( 'shortdesc' => 'Pasa los tipos y taxonomías del módulo site a los nombres con prefijo de la 1.2.0 (wp_posts, términos, contenido, plantillas, menús, WPML, Rank Math). Con --dry-run solo lo cuenta.' )
	);
}
