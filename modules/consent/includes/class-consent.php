<?php
/**
 * Aviso de cookies (consentimiento) propio y mínimo — 0.5.0.
 *
 * Qué es: un aviso con Aceptar / Rechazar / Configurar (los tres con el mismo
 * peso visual, como pide la AEPD), categorías declarables, la elección en una
 * cookie propia y técnica (estrategia_web_consent), y la elección expuesta:
 * - a PHP:  estrategia_web_consent_has( 'maps' ), estrategia_web_consent_categories()
 *           (includes/consent-functions.php; por dentro, esta clase);
 * - a JS:   window.estrategiaWebConsent.has( 'maps' ) / .get() / .open() /
 *           .grant( 'maps' ) / .categories, el evento «estrategia-web:consent» en
 *           document (detail.accepted), «estrategia-web:consent-ready» (0.5.0, una
 *           vez al arrancar, también sin elección) y el store de Interactivity
 *           «estrategia-web/consent» (blocks/consent/view.js);
 * - y activa solo los <script type="text/plain" data-estrategia-web-consent="X">
 *   de las categorías aceptadas.
 * El aviso se pinta en wp_body_open, al PRINCIPIO del body (no depende de que
 * el tema ponga un bloque); si el tema no llama a wp_body_open(), en
 * wp_footer, como antes de la 0.5.1.
 *
 * 1.1.0 — CATEGORÍAS, DETECTOR Y TABLA DE LA POLÍTICA
 *
 * Las categorías son las de la AEPD: necesarias (siempre, no se preguntan),
 * preferencias, analítica y publicidad, más «mapas y contenido de terceros»,
 * que ya existía. Los SLUGS no cambian, porque la revisión del aviso es un
 * hash de los slugs y cambiarlos haría que TODOS los visitantes que ya
 * eligieron vieran el aviso otra vez (trampa 2):
 *     necessary    Necesarias               (no se guarda: siempre)
 *     preferences  Preferencias             (nueva en 1.1.0)
 *     statistics   Analítica                (antes se rotulaba «Estadísticas»)
 *     marketing    Publicidad               (antes se rotulaba «Marketing»)
 *     maps         Mapas y contenido de terceros
 * Se admiten como ALIAS (al declarar y al preguntar, en PHP y en JS):
 * analytics → statistics, advertising y ads → marketing. Así un módulo nuevo
 * puede escribir has( 'analytics' ) sin saber la historia.
 * - «preferences» se añadió al FINAL de known_categories() a propósito: el
 *   orden de esa lista entra en la revisión. Al pintar, el aviso las ordena
 *   con display_order() (preferencias, analítica, publicidad, mapas), que no
 *   toca la revisión.
 * - Quién declara categorías: el CÓDIGO con el filtro de siempre (el tema,
 *   Matomo híbrido, google-tags) y, desde 1.1.0, el ADMINISTRADOR en
 *   «Estrategia Web → Cookies» (opción estrategia_web_cookies_settings, clave
 *   ask). Se unen; el administrador no puede quitar lo que declara el código.
 *   code_categories() da solo las del código.
 * - REABRIR EL PANEL (para el pie): el bloque «Configurar cookies», el
 *   shortcode [estrategia_web_consent_settings], window.estrategiaWebConsent.open()
 *   y, desde 1.1.0, CUALQUIER enlace a «#estrategia-web-consent» o elemento con
 *   la clase «estrategia-web-consent-open» (o el atributo
 *   data-estrategia-web-consent-open): sirve para un enlace del menú de
 *   navegación o un botón del editor con «Clase CSS adicional».
 * - El DETECTOR, el REGISTRO de cookies y la TABLA de la política tienen su
 *   cabecera propia: includes/class-cookie-registry.php, class-cookie-rest.php
 *   y class-cookie-table.php; la pantalla, admin/class-cookies-page.php.
 *
 * ORDEN DE FOCO (0.5.1, WCAG 2.4.3). En el pie quedaba el último de la
 * página: con teclado había que pasar 30-35 tabuladores para llegar a
 * Aceptar / Rechazar (auditoría de Lavandería, 06-10-2026). Ahora va el
 * primero en el DOM y el primer Tab cae en él, aunque se vea abajo (es
 * position: fixed). Decisiones:
 * - Primero en el DOM y NO mover el foco al abrirse: robar el foco al cargar
 *   la página desorienta al lector de pantalla y salta por encima del
 *   «Saltar al contenido». Así el primer Tab lo encuentra, y quien no quiera
 *   contestar sigue tabulando hacia la página.
 * - Sin trampa de foco: es role="dialog" con aria-modal="false" y la página
 *   sigue usable; Tab sale de él y Mayús+Tab vuelve. La AEPD (Guía sobre el
 *   uso de las cookies, 2023) pide que la primera capa se vea y que aceptar
 *   y rechazar estén al mismo nivel, no que bloquee la web.
 * - Escape NO lo cierra la primera vez: cerrar sin elegir dejaría al
 *   visitante sin aviso y sin decisión, y volvería a salir en la página
 *   siguiente. Reabierto desde «Configurar cookies» (ya hay elección), sí.
 * - La trampa: el «Saltar al contenido» del núcleo lo mete un script DENTRO
 *   de .wp-site-blocks, después del aviso; mientras el aviso está abierto es
 *   el segundo destino del tabulador, no el primero. Elegido o no, al
 *   cerrarse vuelve a ser el primero.
 * Prueba: tests/consent-browser.test.mjs («lo PRIMERO al tabular»).
 * El bloque estrategia-web-site/consent es el ENLACE para reabrirlo, pensado para el
 * pie; también hay shortcode [estrategia_web_consent_settings].
 *
 * POR QUÉ ESTÁ AQUÍ Y NO EN EL TEMA PADRE (vino de armazon-wp-padre 0.6.0,
 * inc/consent.php): el consentimiento es funcionalidad, no presentación. Si
 * una web cambia de tema no puede quedarse sin aviso, y Matomo, que está en
 * este plugin, depende de él. Las tres capas (decisión del Armazón
 * 2026-10-01): cómo se ve, del tema; lo que funciona y se guarda, de aquí.
 * Se movió SIN cambiar el contrato: la misma cookie con el mismo formato y
 * la misma revisión (un visitante que ya eligió no vuelve a ver el aviso), el
 * mismo filtro estrategia_web_consent_categories, las mismas funciones PHP, la misma
 * API JS, el mismo evento y el mismo store.
 *
 * 1.0.0, EL CAMBIO DE NOMBRE (Armazón Sitio → Estrategia Web Site): la cookie
 * pasa de armazon_consent a estrategia_web_consent con el MISMO formato y la
 * misma revisión. Se lee la vieja si falta la nueva (choice() y view.js) y el
 * navegador la reescribe con el nombre nuevo y borra la vieja: quien ya
 * eligió NO vuelve a ver el aviso. Filtros, funciones, API JS, evento y store
 * cambian de prefijo (estrategia_web_…, window.estrategiaWebConsent,
 * «estrategia-web:consent»); padre y plugin se actualizan juntos.
 *
 * El nombre del bloque: estrategia-web-site/consent. Los viejos,
 * armazon-site/consent y armazon/consent (tema padre ≤ 0.6.0), son ALIAS
 * ocultos del insertador (includes/class-legacy-names.php) que pintan lo
 * mismo, para el marcado que quede sin migrar. Se quitan en 2.0.0.
 *
 * Convivencia con un tema padre viejo (≤ 0.6.0, que aún trae el aviso): si al
 * llegar after_setup_theme ya existe estrategia_web_consent_categories(), es que el
 * tema la definió; este plugin NO arranca su aviso (habría dos, y PHP
 * revienta al redeclarar las funciones). Por eso las funciones globales se
 * cargan tarde, en after_setup_theme, y no al cargar el plugin: los plugins
 * se cargan ANTES que el functions.php del tema.
 * Y al revés, padre 0.7.0 con un estrategia-web-site viejo (≤ 0.4.0): no hay aviso.
 * Nada opcional se carga (los mapas piden clic; Matomo híbrido se queda sin
 * cookies), así que falla del lado seguro, pero la web pierde el aviso:
 * se actualizan los dos a la vez.
 *
 * Cómo declara categorías un tema o un plugin:
 *
 *     add_filter( 'estrategia_web_consent_categories', fn( $c ) => array_merge( (array) $c, array( 'maps' ) ) );
 *
 * Categorías conocidas: ver arriba (1.1.0). «necessary» siempre está
 * y no se pregunta. SI NO SE DECLARA NINGUNA, EL AVISO NO SALE, y es lo
 * normal: con Matomo sin cookies (fila 16 del Armazón) y los mapas en dos
 * clics (estrategia-web/map del padre), la mayoría de webs no tienen nada que
 * preguntar. Matomo en modo híbrido declara «statistics» él solo
 * (modules/matomo), y google-tags lo que necesite cada etiqueta.
 *
 * «Powered by» (0.5.0): al pie del aviso, «Powered by Estrategia Web» con
 * enlace a https://estrategiaweb.es/, en pestaña nueva, rel="noopener" y SIN
 * nofollow (lo quiere Juan: cuenta como enlace). DESDE 1.0.0 VIENE APAGADA:
 * la guideline 10 de WordPress.org prohíbe enlazar al autor en la web
 * pública sin que lo active quien usa el plugin. Se enciende con el FILTRO
 * estrategia_web_site_consent_powered_by (no con una opción: es la firma de
 * quien hace la web, no un dato que el cliente toque en su escritorio).
 * Lavandería la enciende en su tema hijo.
 *
 *     // Encenderla:
 *     add_filter( 'estrategia_web_site_consent_powered_by', '__return_true' );
 *     // Encendida con otro texto o URL (lo que no se pase, se queda):
 *     add_filter( 'estrategia_web_site_consent_powered_by', fn( $p ) => array_merge( $p, array( 'show' => true, 'text' => 'Hecha por X', 'url' => 'https://x.es/' ) ) );
 *
 * Caso frontera: 'url' vacía pinta el texto sin enlace; 'text' vacío no
 * pinta la línea. El nombre «Estrategia Web» no se traduce (es una marca);
 * «Powered by %s» sí, y en es_ES se deja en inglés, como lo dice CookieYes.
 *
 * WP Consent API (plugin): si está activo, se declara el tipo «optin» y el JS
 * llama a wp_set_consent() al guardar: statistics → statistics y
 * statistics-anonymous, marketing → marketing, preferences → preferences
 * (1.1.0). «maps» no tiene equivalente en esa API y no se comunica. No se
 * depende de ella: sin el plugin, todo igual.
 *
 * Cómo se mantiene:
 * - Quién: quien toque el plugin. Cuándo: al añadir una categoría, cambiar el
 *   texto legal por defecto o tocar el formato de la cookie. Las pruebas son
 *   tests/consent.test.mjs (nivel 2) y tests/consent-browser.test.mjs
 *   (nivel 3, con Matomo híbrido contra este aviso real); las del registro,
 *   tests/cookies.test.mjs y cookies-browser.test.mjs.
 * - Al cambiar las categorías declaradas cambia la «revisión» (hash de la
 *   lista) y el aviso vuelve a salir a todo el mundo: es lo correcto, se les
 *   pregunta por algo nuevo. Si hay que forzarlo sin cambiar categorías (otra
 *   política), el filtro estrategia_web_consent_revision.
 * - Caso frontera: una web necesita una categoría que no se conoce (p. ej.
 *   «chat»). Es un AJUSTE si basta con etiqueta y descripción: se añade aquí
 *   en una versión menor, AL FINAL de known_categories() (como preferences
 *   en 1.1.0) y en DISPLAY_ORDER. Si necesita otra lógica, es de esa web.
 * - La trampa 1: estrategia_web_consent_has() lee la cookie en el SERVIDOR. Con
 *   caché de página (SiteGround, etc.) la página cacheada de un visitante se
 *   sirve a otro. Para decidir si se carga algo, mejor en el navegador
 *   (text/plain + data-estrategia-web-consent, o el evento).
 * - La trampa 2: la revisión es md5 de la lista + el filtro de revisión, y
 *   tiene que dar EXACTAMENTE lo mismo que daba el padre (lo comprueba
 *   tests/consent.test.mjs con el valor calculado a mano). Si se toca el
 *   orden de las categorías conocidas, la revisión cambia y TODOS los
 *   visitantes vuelven a ver el aviso.
 * - La trampa 3: los filtros de los textos (estrategia_web_consent_text, …)
 *   cambiaron de prefijo en 1.0.0 con todo lo demás: un tema o plugin que
 *   enganchaba armazon_consent_… deja de afinar el aviso hasta que se renombre.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Consent {

	/** Cookie técnica con la elección (1.0.0; antes armazon_consent). */
	public const COOKIE = 'estrategia_web_consent';

	/**
	 * La cookie con el nombre de antes de 1.0.0. Se LEE si falta la nueva, y
	 * el navegador la pasa a la nueva y la borra (blocks/consent/view.js):
	 * quien ya eligió no vuelve a ver el aviso por el cambio de nombre. Se
	 * quita en 2.0.0, con los alias de Legacy_Names.
	 */
	public const LEGACY_COOKIE = 'armazon_consent';

	/** Nombre del bloque del enlace. Los viejos (armazon/consent, armazon-site/consent) son alias: Legacy_Names. */
	public const BLOCK = 'estrategia-web-site/consent';

	/** Handles que WordPress deriva de blocks/consent/block.json. */
	public const SCRIPT_MODULE = 'estrategia-web-site-consent-view-script-module';
	public const STYLE         = 'estrategia-web-site-consent-style';

	/** 1.1.0: otros nombres que se aceptan para una categoría (ver la cabecera). */
	public const ALIASES = array(
		'analytics'   => 'statistics',
		'advertising' => 'marketing',
		'ads'         => 'marketing',
	);

	/** 1.1.0: orden en que el aviso y la pantalla las enseñan (no toca la revisión). */
	public const DISPLAY_ORDER = array( 'preferences', 'statistics', 'marketing', 'maps' );

	private static bool $active = false;

	public static function boot(): void {
		add_action( 'after_setup_theme', array( self::class, 'take_over' ), 0 );
	}

	/** Si el aviso es de este plugin en esta petición (false con un tema padre viejo que aún lo trae). */
	public static function active(): bool {
		return self::$active;
	}

	/** Arranca el aviso salvo que el tema ya lo defina (ver la cabecera, «Convivencia»). */
	public static function take_over(): void {
		if ( function_exists( 'estrategia_web_consent_categories' ) ) {
			return;
		}
		self::$active = true;
		require_once ESTRATEGIA_WEB_CONSENT_DIR . 'includes/consent-functions.php';

		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'init', array( self::class, 'register_settings' ) );
		add_filter( 'block_type_metadata', array( self::class, 'block_labels' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		// 0.5.1: al PRINCIPIO del body (orden de foco, ver la cabecera); en el
		// pie solo si el tema no llama a wp_body_open().
		add_action( 'wp_body_open', array( self::class, 'print_notice' ), 5 );
		add_action( 'wp_footer', array( self::class, 'print_notice' ), 5 );
		add_shortcode( 'estrategia_web_consent_settings', array( self::class, 'shortcode' ) );
		// 1.1.0: registro de cookies, detector y tabla de la política.
		add_shortcode( Cookie_Table::SHORTCODE, array( Cookie_Table::class, 'shortcode' ) );
		add_action( 'rest_api_init', array( Cookie_Rest::class, 'register' ) );
		if ( is_admin() ) {
			require_once ESTRATEGIA_WEB_CONSENT_DIR . 'includes/admin/class-cookies-page.php';
			Cookies_Page::boot();
		}
		if ( function_exists( 'wp_has_consent' ) ) {
			add_filter( 'wp_get_consent_type', static fn( $type ) => self::categories() ? 'optin' : $type );
		}
	}

	/**
	 * Categorías opcionales que se saben describir. EL ORDEN ENTRA EN LA
	 * REVISIÓN: una nueva va al final (trampa 2).
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function known_categories(): array {
		return array(
			'statistics'  => array(
				'label'       => __( 'Analytics', 'estrategia-web' ),
				'description' => __( 'Help us understand how the site is used.', 'estrategia-web' ),
			),
			'maps'        => array(
				'label'       => __( 'Maps and third-party content', 'estrategia-web' ),
				'description' => __( 'Load embedded maps and other content served by third parties, which may set their own cookies.', 'estrategia-web' ),
			),
			'marketing'   => array(
				'label'       => __( 'Advertising', 'estrategia-web' ),
				'description' => __( 'Measure campaigns and show relevant advertising.', 'estrategia-web' ),
			),
			'preferences' => array(
				'label'       => __( 'Preferences', 'estrategia-web' ),
				'description' => __( 'Remember choices that change how the site behaves or looks, such as the region or recently viewed items.', 'estrategia-web' ),
			),
		);
	}

	/** Un slug de categoría con los alias resueltos («analytics» → «statistics»). */
	public static function normalize( string $category ): string {
		$category = strtolower( trim( $category ) );
		return self::ALIASES[ $category ] ?? $category;
	}

	/**
	 * Las categorías en el orden de enseñarlas (DISPLAY_ORDER).
	 *
	 * @param string[] $categories
	 * @return string[]
	 */
	public static function display_order( array $categories ): array {
		$order = array_flip( self::DISPLAY_ORDER );
		usort( $categories, static fn( $a, $b ) => ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 ) );
		return $categories;
	}

	/**
	 * Las que declara el CÓDIGO (el filtro), en el orden estable de known_categories().
	 *
	 * @return string[]
	 */
	public static function code_categories(): array {
		$declared = array_map( static fn( $c ) => self::normalize( (string) $c ), (array) apply_filters( 'estrategia_web_consent_categories', array() ) );
		return array_values( array_intersect( array_keys( self::known_categories() ), $declared ) );
	}

	/**
	 * Categorías opcionales que pregunta el aviso: las del código y las que
	 * añade el administrador (1.1.0), en un orden estable.
	 *
	 * @return string[]
	 */
	public static function categories(): array {
		$declared = array_merge( self::code_categories(), Cookie_Registry::settings()['ask'] );
		return array_values( array_intersect( array_keys( self::known_categories() ), $declared ) );
	}

	/** Revisión: una elección guardada con otra revisión no vale y el aviso vuelve a salir. */
	public static function revision(): string {
		$seed = implode( ',', self::categories() ) . '|' . apply_filters( 'estrategia_web_consent_revision', '1' );
		return substr( md5( $seed ), 0, 8 );
	}

	/** Vida de la elección, en segundos. 12 meses (la AEPD recomienda no pasar de 24). */
	public static function max_age(): int {
		return (int) apply_filters( 'estrategia_web_consent_max_age', YEAR_IN_SECONDS );
	}

	/**
	 * La elección guardada, o null si no hay ninguna válida para la revisión.
	 * Formato de la cookie: "<revisión>.<cat~cat>.<unix>".
	 *
	 * @return string[]|null Categorías opcionales aceptadas.
	 */
	public static function choice(): ?array {
		$name = empty( $_COOKIE[ self::COOKIE ] ) ? self::LEGACY_COOKIE : self::COOKIE;
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}
		$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
		$parts = explode( '.', $raw );
		if ( 3 !== count( $parts ) || self::revision() !== $parts[0] ) {
			return null;
		}
		$accepted = '' === $parts[1] ? array() : explode( '~', $parts[1] );
		return array_values( array_intersect( self::categories(), $accepted ) );
	}

	/** Si una categoría está aceptada. «necessary» siempre. Admite los alias. */
	public static function has( string $category ): bool {
		$category = self::normalize( $category );
		if ( 'necessary' === $category ) {
			return true;
		}
		$choice = self::choice();
		return null !== $choice && in_array( $category, $choice, true );
	}

	/**
	 * La línea «Powered by» ya filtrada. Ver la cabecera.
	 *
	 * @return array{show: bool, text: string, url: string}
	 */
	public static function powered_by(): array {
		$defaults = array(
			// APAGADA por defecto (1.0.0): WordPress.org (guideline 10) no deja
			// enlazar al autor en la web pública sin que lo active quien la usa.
			'show' => false,
			/* translators: %s: name of the company that built the site (a brand, not translated). */
			'text' => sprintf( __( 'Powered by %s', 'estrategia-web' ), 'Estrategia Web' ),
			'url'  => 'https://estrategiaweb.es/',
		);
		/**
		 * La línea «Powered by» del aviso de cookies.
		 *
		 * @param array{show: bool, text: string, url: string}|false $powered false la quita.
		 */
		$value = apply_filters( 'estrategia_web_site_consent_powered_by', $defaults );
		if ( ! is_array( $value ) ) {
			$value = array( 'show' => (bool) $value );
		}
		$value = array_merge( $defaults, $value );
		return array(
			'show' => (bool) $value['show'] && '' !== trim( (string) $value['text'] ),
			'text' => (string) $value['text'],
			'url'  => (string) esc_url_raw( (string) $value['url'], array( 'http', 'https' ) ),
		);
	}

	/** Registra el bloque del enlace y el de la tabla (1.1.0). Los nombres viejos del enlace son alias de Legacy_Names. */
	public static function register_blocks(): void {
		register_block_type( ESTRATEGIA_WEB_CONSENT_DIR . 'blocks/consent' );
		register_block_type( ESTRATEGIA_WEB_CONSENT_DIR . 'blocks/cookie-table' );
	}

	/** 1.1.0: los ajustes del registro, también por /wp/v2/settings (para scripts y pruebas). */
	public static function register_settings(): void {
		register_setting(
			'estrategia_web_cookies',
			Cookie_Registry::SETTINGS,
			array(
				'type'              => 'object',
				'description'       => __( 'Cookie notice: detector on or off, and categories added by the administrator.', 'estrategia-web' ),
				'sanitize_callback' => array( Cookie_Registry::class, 'sanitize_settings' ),
				'default'           => array(
					'detector' => true,
					'ask'      => array(),
				),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'detector' => array( 'type' => 'boolean' ),
							'ask'      => array(
								'type'  => 'array',
								'items' => array(
									'type' => 'string',
									'enum' => array_keys( self::known_categories() ),
								),
							),
						),
					),
				),
			)
		);
	}

	/** Etiqueta del control de autoRegister (block.json no traduce la clave «label»). */
	private static function label_text(): string {
		return __( 'Link text (empty: “Cookie settings”)', 'estrategia-web' );
	}

	/**
	 * Pone la etiqueta traducida al atributo del bloque.
	 *
	 * @param array $metadata block.json leído.
	 */
	public static function block_labels( array $metadata ): array {
		if ( ( $metadata['name'] ?? '' ) === self::BLOCK && isset( $metadata['attributes']['label'] ) ) {
			$metadata['attributes']['label']['label'] = self::label_text();
		}
		return $metadata;
	}

	/** Estado de Interactivity que comparten el aviso y el enlace. */
	public static function state(): void {
		$known    = self::known_categories();
		$settings = Cookie_Registry::settings();
		wp_interactivity_state(
			'estrategia-web/consent',
			array(
				'categories'   => self::categories(),
				'labels'       => array_map( static fn( $c ) => $c['label'], array_intersect_key( $known, array_flip( self::categories() ) ) ),
				'aliases'      => self::ALIASES,
				'revision'     => self::revision(),
				'cookieName'   => self::COOKIE,
				'legacyCookie' => self::LEGACY_COOKIE,
				'maxAge'       => self::max_age(),
				'isOpen'       => false,
				'showSettings' => false,
				// 1.1.0: el detector (blocks/consent/view.js, class-cookie-rest.php).
				// Sin «versión de la lista del servidor» a propósito: la cambia el
				// propio informe (entra una fila) y, con caché de página, cada
				// visitante vería una versión vieja y volvería a mandar en cada
				// página. La «versión» que cuenta es la lista de NOMBRES del
				// visitante: se manda si cambia o si pasan «days» días.
				'detector'     => $settings['detector'] ? array(
					'url'   => rest_url( Cookie_Rest::NAMESPACE . '/cookies/seen' ),
					'days'  => (int) apply_filters( 'estrategia_web_cookies_report_days', 7 ),
					'delay' => 4000,
				) : null,
			)
		);
	}

	/** Pone el módulo y la hoja en la web cuando hay algo que preguntar. */
	public static function enqueue(): void {
		if ( ! self::categories() ) {
			return;
		}
		self::state();
		wp_enqueue_script_module( self::SCRIPT_MODULE );
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * Pinta el aviso. Oculto por defecto: view.js lo enseña si no hay elección
	 * válida. Sin JavaScript se queda oculto, y tampoco se carga nada opcional
	 * (los scripts son text/plain), así que no se pierde nada.
	 */
	public static function print_notice(): void {
		static $printed = false;
		$categories = self::categories();
		if ( ! $categories || $printed ) {
			return;
		}
		$printed = true;
		$known   = self::known_categories();
		$policy  = get_privacy_policy_url();
		$powered = self::powered_by();
		$text    = apply_filters(
			'estrategia_web_consent_text',
			__( 'We use our own and third-party cookies for the purposes listed below. You can accept them, reject them or choose which ones to allow. Necessary cookies are always active.', 'estrategia-web' )
		);

		ob_start();
		?>
		<section
			id="estrategia-web-consent"
			class="estrategia-web-consent"
			hidden
			role="dialog"
			aria-modal="false"
			aria-labelledby="estrategia-web-consent-title"
			aria-describedby="estrategia-web-consent-text"
			data-wp-interactive="estrategia-web/consent"
			data-wp-init="callbacks.init"
			data-wp-watch="callbacks.focusOnOpen"
			data-wp-bind--hidden="!state.isOpen"
			data-wp-on--keydown="actions.onKeydown"
		>
			<h2 id="estrategia-web-consent-title" class="estrategia-web-consent__title" tabindex="-1"><?php esc_html_e( 'Cookies', 'estrategia-web' ); ?></h2>
			<p id="estrategia-web-consent-text" class="estrategia-web-consent__text">
				<?php echo esc_html( $text ); ?>
				<?php if ( $policy ) : ?>
					<a href="<?php echo esc_url( $policy ); ?>"><?php esc_html_e( 'Privacy and cookie policy', 'estrategia-web' ); ?></a>
				<?php endif; ?>
			</p>

			<form class="estrategia-web-consent__settings" id="estrategia-web-consent-settings" hidden data-wp-bind--hidden="!state.showSettings" data-wp-on--submit="actions.saveSettings">
				<fieldset>
					<legend><?php esc_html_e( 'Cookie categories', 'estrategia-web' ); ?></legend>
					<label class="estrategia-web-consent__category">
						<input type="checkbox" checked disabled>
						<span><strong><?php esc_html_e( 'Necessary', 'estrategia-web' ); ?></strong> — <?php esc_html_e( 'Required for the site to work, including remembering this choice. Always active.', 'estrategia-web' ); ?></span>
					</label>
					<?php foreach ( self::display_order( $categories ) as $slug ) : ?>
					<label class="estrategia-web-consent__category">
						<input
							type="checkbox"
							name="estrategia-web-consent-category"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php echo wp_interactivity_data_wp_context( array( 'category' => $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							data-wp-bind--checked="state.isChecked"
							data-wp-on--change="actions.toggleCategory"
						>
						<span><strong><?php echo esc_html( $known[ $slug ]['label'] ); ?></strong> — <?php echo esc_html( $known[ $slug ]['description'] ); ?></span>
					</label>
					<?php endforeach; ?>
				</fieldset>
				<button type="submit" class="wp-element-button estrategia-web-consent__button"><?php esc_html_e( 'Save choices', 'estrategia-web' ); ?></button>
			</form>

			<div class="estrategia-web-consent__actions">
				<button type="button" class="wp-element-button estrategia-web-consent__button" data-estrategia-web-consent-action="accept" data-wp-on--click="actions.acceptAll"><?php esc_html_e( 'Accept all', 'estrategia-web' ); ?></button>
				<button type="button" class="wp-element-button estrategia-web-consent__button" data-estrategia-web-consent-action="reject" data-wp-on--click="actions.rejectAll"><?php esc_html_e( 'Reject all', 'estrategia-web' ); ?></button>
				<button type="button" class="wp-element-button estrategia-web-consent__button" data-estrategia-web-consent-action="configure" aria-controls="estrategia-web-consent-settings" data-wp-bind--aria-expanded="state.showSettings" data-wp-on--click="actions.toggleSettings"><?php esc_html_e( 'Configure', 'estrategia-web' ); ?></button>
			</div>
			<?php if ( $powered['show'] ) : ?>
			<p class="estrategia-web-consent__powered">
				<?php if ( '' !== $powered['url'] ) : ?>
				<a href="<?php echo esc_url( $powered['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $powered['text'] ); ?><span class="estrategia-web-consent__sr"> <?php esc_html_e( '(opens in a new tab)', 'estrategia-web' ); ?></span></a>
				<?php else : ?>
				<?php echo esc_html( $powered['text'] ); ?>
				<?php endif; ?>
			</p>
			<?php endif; ?>
		</section>
		<?php
		echo wp_interactivity_process_directives( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput -- built above with escaping.
	}

	/**
	 * HTML del enlace «Configurar cookies»: el bloque, su alias y el shortcode.
	 *
	 * - Es un <button>, no un <a href="#">: abre algo en la página, no navega.
	 * - Si la web no declara categorías no pinta nada (no hay aviso que
	 *   reabrir). En el editor sí enseña una nota, para que no parezca roto.
	 *
	 * @param array $attributes Atributos del bloque (label).
	 */
	public static function render_link( array $attributes ): string {
		if ( ! self::categories() ) {
			if ( wp_is_serving_rest_request() ) {
				return sprintf(
					'<p %s>%s</p>',
					get_block_wrapper_attributes(),
					esc_html__( 'Cookie settings: this site declares no optional cookie categories, so there is no notice to reopen and this link is not shown.', 'estrategia-web' )
				);
			}
			return '';
		}
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		$label = '' !== $label ? $label : __( 'Cookie settings', 'estrategia-web' );
		return sprintf(
			'<p %s><button type="button" class="estrategia-web-consent-link__button" aria-controls="estrategia-web-consent" data-wp-interactive="estrategia-web/consent" data-wp-on--click="actions.open">%s</button></p>',
			get_block_wrapper_attributes( array( 'class' => 'estrategia-web-consent-link' ) ),
			esc_html( $label )
		);
	}

	/**
	 * [estrategia_web_consent_settings label="…"]: lo mismo que el bloque.
	 *
	 * @param array|string $atts Atributos del shortcode.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'label' => '' ), $atts, 'estrategia_web_consent_settings' );
		return render_block(
			array(
				'blockName'    => self::BLOCK,
				'attrs'        => array( 'label' => (string) $atts['label'] ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}
}
