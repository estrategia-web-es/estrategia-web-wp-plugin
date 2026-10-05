<?php
/**
 * Aviso de cookies (consentimiento) propio y mínimo — 0.5.0.
 *
 * Qué es: un aviso con Aceptar / Rechazar / Configurar (los tres con el mismo
 * peso visual, como pide la AEPD), categorías declarables, la elección en una
 * cookie propia y técnica (armazon_consent), y la elección expuesta:
 * - a PHP:  armazon_consent_has( 'maps' ), armazon_consent_categories()
 *           (includes/consent-functions.php; por dentro, esta clase);
 * - a JS:   window.armazonConsent.has( 'maps' ) / .get() / .open() /
 *           .grant( 'maps' ) / .categories, el evento «armazon:consent» en
 *           document (detail.accepted), «armazon:consent-ready» (0.5.0, una
 *           vez al arrancar, también sin elección) y el store de Interactivity
 *           «armazon/consent» (blocks/consent/view.js);
 * - y activa solo los <script type="text/plain" data-armazon-consent="X">
 *   de las categorías aceptadas.
 * El aviso se pinta en wp_footer (no depende de que el tema ponga un bloque).
 * El bloque armazon-site/consent es el ENLACE para reabrirlo, pensado para el
 * pie; también hay shortcode [armazon_consent_settings].
 *
 * POR QUÉ ESTÁ AQUÍ Y NO EN EL TEMA PADRE (vino de armazon-wp-padre 0.6.0,
 * inc/consent.php): el consentimiento es funcionalidad, no presentación. Si
 * una web cambia de tema no puede quedarse sin aviso, y Matomo, que está en
 * este plugin, depende de él. Las tres capas (decisión del Armazón
 * 2026-10-01): cómo se ve, del tema; lo que funciona y se guarda, de aquí.
 * Se movió SIN cambiar el contrato: la misma cookie con el mismo formato y
 * la misma revisión (un visitante que ya eligió no vuelve a ver el aviso), el
 * mismo filtro armazon_consent_categories, las mismas funciones PHP, la misma
 * API JS, el mismo evento y el mismo store.
 *
 * El nombre del bloque: armazon-site/consent (el espacio de nombres es de
 * quien lo registra). El viejo, armazon/consent, se sigue registrando como
 * ALIAS oculto del insertador, con el mismo HTML, para que el contenido y las
 * plantillas ya guardadas con él (bases de datos de webs publicadas, partes
 * personalizadas en el Site Editor) sigan pintando el enlace. Lo nuevo se
 * escribe con el nombre nuevo. Quitar el alias es versión MAYOR.
 *
 * Convivencia con un tema padre viejo (≤ 0.6.0, que aún trae el aviso): si al
 * llegar after_setup_theme ya existe armazon_consent_categories(), es que el
 * tema la definió; este plugin NO arranca su aviso (habría dos, y PHP
 * revienta al redeclarar las funciones). Por eso las funciones globales se
 * cargan tarde, en after_setup_theme, y no al cargar el plugin: los plugins
 * se cargan ANTES que el functions.php del tema.
 * Y al revés, padre 0.7.0 con un armazon-site viejo (≤ 0.4.0): no hay aviso.
 * Nada opcional se carga (los mapas piden clic; Matomo híbrido se queda sin
 * cookies), así que falla del lado seguro, pero la web pierde el aviso:
 * se actualizan los dos a la vez.
 *
 * Cómo declara categorías un tema o un plugin:
 *
 *     add_filter( 'armazon_consent_categories', fn( $c ) => array_merge( (array) $c, array( 'maps' ) ) );
 *
 * Categorías conocidas: statistics, maps, marketing. «necessary» siempre está
 * y no se pregunta. SI NO SE DECLARA NINGUNA, EL AVISO NO SALE, y es lo
 * normal: con Matomo sin cookies (fila 16 del Armazón) y los mapas en dos
 * clics (armazon/map del padre), la mayoría de webs no tienen nada que
 * preguntar. Matomo en modo híbrido declara «statistics» él solo
 * (includes/class-matomo.php).
 *
 * «Powered by» (0.5.0): al pie del aviso, «Powered by Estrategia Web» con
 * enlace a https://estrategiaweb.es/, en pestaña nueva, rel="noopener" y SIN
 * nofollow (lo quiere Juan: cuenta como enlace). Se cambia con el FILTRO
 * armazon_site_consent_powered_by, no con una opción: es la firma de quien
 * hace la web, no un dato que el cliente toque en su escritorio, y una
 * opción sería una clave más del contrato de armazon_site.
 *
 *     // Otro texto o URL (lo que no se pase, se queda):
 *     add_filter( 'armazon_site_consent_powered_by', fn( $p ) => array_merge( $p, array( 'text' => 'Hecha por X', 'url' => 'https://x.es/' ) ) );
 *     // Quitarla:
 *     add_filter( 'armazon_site_consent_powered_by', '__return_false' );
 *
 * Caso frontera: 'url' vacía pinta el texto sin enlace; 'text' vacío no
 * pinta la línea. El nombre «Estrategia Web» no se traduce (es una marca);
 * «Powered by %s» sí, y en es_ES se deja en inglés, como lo dice CookieYes.
 *
 * WP Consent API (plugin): si está activo, se declara el tipo «optin» y el JS
 * llama a wp_set_consent() al guardar: statistics → statistics y
 * statistics-anonymous, marketing → marketing. «maps» no tiene equivalente en
 * esa API y no se comunica. No se depende de ella: sin el plugin, todo igual.
 *
 * Cómo se mantiene:
 * - Quién: quien toque el plugin. Cuándo: al añadir una categoría, cambiar el
 *   texto legal por defecto o tocar el formato de la cookie. Las pruebas son
 *   tests/consent.test.mjs (nivel 2) y tests/consent-browser.test.mjs
 *   (nivel 3, con Matomo híbrido contra este aviso real).
 * - Al cambiar las categorías declaradas cambia la «revisión» (hash de la
 *   lista) y el aviso vuelve a salir a todo el mundo: es lo correcto, se les
 *   pregunta por algo nuevo. Si hay que forzarlo sin cambiar categorías (otra
 *   política), el filtro armazon_consent_revision.
 * - Caso frontera: una web necesita una categoría que no se conoce (p. ej.
 *   «chat»). Es un AJUSTE si basta con etiqueta y descripción: se añade aquí
 *   en una versión menor. Si necesita otra lógica, es de esa web.
 * - La trampa 1: armazon_consent_has() lee la cookie en el SERVIDOR. Con
 *   caché de página (SiteGround, etc.) la página cacheada de un visitante se
 *   sirve a otro. Para decidir si se carga algo, mejor en el navegador
 *   (text/plain + data-armazon-consent, o el evento).
 * - La trampa 2: la revisión es md5 de la lista + el filtro de revisión, y
 *   tiene que dar EXACTAMENTE lo mismo que daba el padre (lo comprueba
 *   tests/consent.test.mjs con el valor calculado a mano). Si se toca el
 *   orden de las categorías conocidas, la revisión cambia y TODOS los
 *   visitantes vuelven a ver el aviso.
 * - La trampa 3: los filtros de los textos (armazon_consent_text, …) se
 *   llaman igual que en el padre a propósito; no se renombran.
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Consent {

	/** Cookie técnica con la elección. El mismo nombre que usaba el tema padre. */
	public const COOKIE = 'armazon_consent';

	/** Nombre del bloque del enlace. */
	public const BLOCK = 'armazon-site/consent';

	/** Nombre viejo (tema padre ≤ 0.6.0), registrado como alias. */
	public const LEGACY_BLOCK = 'armazon/consent';

	/** Handles que WordPress deriva de blocks/consent/block.json. */
	public const SCRIPT_MODULE = 'armazon-site-consent-view-script-module';
	public const STYLE         = 'armazon-site-consent-style';

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
		if ( function_exists( 'armazon_consent_categories' ) ) {
			return;
		}
		self::$active = true;
		require_once ARMAZON_SITE_DIR . 'includes/consent-functions.php';

		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_filter( 'block_type_metadata', array( self::class, 'block_labels' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_footer', array( self::class, 'print_notice' ), 5 );
		add_shortcode( 'armazon_consent_settings', array( self::class, 'shortcode' ) );
		if ( function_exists( 'wp_has_consent' ) ) {
			add_filter( 'wp_get_consent_type', static fn( $type ) => self::categories() ? 'optin' : $type );
		}
	}

	/**
	 * Categorías opcionales que se saben describir.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function known_categories(): array {
		return array(
			'statistics' => array(
				'label'       => __( 'Statistics', 'armazon-site' ),
				'description' => __( 'Help us understand how the site is used.', 'armazon-site' ),
			),
			'maps'       => array(
				'label'       => __( 'Maps and third-party content', 'armazon-site' ),
				'description' => __( 'Load embedded maps and other content served by third parties, which may set their own cookies.', 'armazon-site' ),
			),
			'marketing'  => array(
				'label'       => __( 'Marketing', 'armazon-site' ),
				'description' => __( 'Measure campaigns and show relevant advertising.', 'armazon-site' ),
			),
		);
	}

	/**
	 * Categorías opcionales declaradas por la web, en un orden estable.
	 *
	 * @return string[]
	 */
	public static function categories(): array {
		$declared = (array) apply_filters( 'armazon_consent_categories', array() );
		$known    = array_keys( self::known_categories() );
		return array_values( array_intersect( $known, $declared ) );
	}

	/** Revisión: una elección guardada con otra revisión no vale y el aviso vuelve a salir. */
	public static function revision(): string {
		$seed = implode( ',', self::categories() ) . '|' . apply_filters( 'armazon_consent_revision', '1' );
		return substr( md5( $seed ), 0, 8 );
	}

	/** Vida de la elección, en segundos. 12 meses (la AEPD recomienda no pasar de 24). */
	public static function max_age(): int {
		return (int) apply_filters( 'armazon_consent_max_age', YEAR_IN_SECONDS );
	}

	/**
	 * La elección guardada, o null si no hay ninguna válida para la revisión.
	 * Formato de la cookie: "<revisión>.<cat~cat>.<unix>".
	 *
	 * @return string[]|null Categorías opcionales aceptadas.
	 */
	public static function choice(): ?array {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}
		$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		$parts = explode( '.', $raw );
		if ( 3 !== count( $parts ) || self::revision() !== $parts[0] ) {
			return null;
		}
		$accepted = '' === $parts[1] ? array() : explode( '~', $parts[1] );
		return array_values( array_intersect( self::categories(), $accepted ) );
	}

	/** Si una categoría está aceptada. «necessary» siempre. */
	public static function has( string $category ): bool {
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
			'show' => true,
			/* translators: %s: name of the company that built the site (a brand, not translated). */
			'text' => sprintf( __( 'Powered by %s', 'armazon-site' ), 'Estrategia Web' ),
			'url'  => 'https://estrategiaweb.es/',
		);
		/**
		 * La línea «Powered by» del aviso de cookies.
		 *
		 * @param array{show: bool, text: string, url: string}|false $powered false la quita.
		 */
		$value = apply_filters( 'armazon_site_consent_powered_by', $defaults );
		if ( ! is_array( $value ) ) {
			return array_merge( $defaults, array( 'show' => (bool) $value ) );
		}
		$value = array_merge( $defaults, $value );
		return array(
			'show' => (bool) $value['show'] && '' !== trim( (string) $value['text'] ),
			'text' => (string) $value['text'],
			'url'  => (string) esc_url_raw( (string) $value['url'], array( 'http', 'https' ) ),
		);
	}

	/** Registra el bloque del enlace y su alias con el nombre viejo. */
	public static function register_blocks(): void {
		register_block_type( ARMAZON_SITE_DIR . 'blocks/consent' );
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::LEGACY_BLOCK ) ) {
			return;
		}
		register_block_type(
			self::LEGACY_BLOCK,
			array(
				'api_version'            => 3,
				'title'                  => _x( 'Cookie settings link', 'block title', 'armazon-site' ),
				'category'               => 'widgets',
				'icon'                   => 'shield',
				'attributes'             => array(
					'label' => array(
						'type'    => 'string',
						'default' => '',
						'label'   => self::label_text(),
					),
				),
				'supports'               => array(
					'inserter'      => false,
					'autoRegister'  => true,
					'html'          => false,
					'interactivity' => true,
					'typography'    => array( 'fontSize' => true ),
					'color'         => array( 'text' => true ),
				),
				'render_callback'        => static fn( $attributes ) => self::render_link( (array) $attributes ),
				'style_handles'          => array( self::STYLE ),
				'view_script_module_ids' => array( self::SCRIPT_MODULE ),
			)
		);
	}

	/** Etiqueta del control de autoRegister (block.json no traduce la clave «label»). */
	private static function label_text(): string {
		return __( 'Link text (empty: “Cookie settings”)', 'armazon-site' );
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
		$known = self::known_categories();
		wp_interactivity_state(
			'armazon/consent',
			array(
				'categories'   => self::categories(),
				'labels'       => array_map( static fn( $c ) => $c['label'], array_intersect_key( $known, array_flip( self::categories() ) ) ),
				'revision'     => self::revision(),
				'cookieName'   => self::COOKIE,
				'maxAge'       => self::max_age(),
				'isOpen'       => false,
				'showSettings' => false,
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
		$categories = self::categories();
		if ( ! $categories ) {
			return;
		}
		$known   = self::known_categories();
		$policy  = get_privacy_policy_url();
		$powered = self::powered_by();
		$text    = apply_filters(
			'armazon_consent_text',
			__( 'We use our own and third-party cookies for the purposes listed below. You can accept them, reject them or choose which ones to allow. Necessary cookies are always active.', 'armazon-site' )
		);

		ob_start();
		?>
		<section
			id="armazon-consent"
			class="armazon-consent"
			hidden
			role="dialog"
			aria-modal="false"
			aria-labelledby="armazon-consent-title"
			aria-describedby="armazon-consent-text"
			data-wp-interactive="armazon/consent"
			data-wp-init="callbacks.init"
			data-wp-watch="callbacks.focusOnOpen"
			data-wp-bind--hidden="!state.isOpen"
			data-wp-on--keydown="actions.onKeydown"
		>
			<h2 id="armazon-consent-title" class="armazon-consent__title" tabindex="-1"><?php esc_html_e( 'Cookies', 'armazon-site' ); ?></h2>
			<p id="armazon-consent-text" class="armazon-consent__text">
				<?php echo esc_html( $text ); ?>
				<?php if ( $policy ) : ?>
					<a href="<?php echo esc_url( $policy ); ?>"><?php esc_html_e( 'Privacy and cookie policy', 'armazon-site' ); ?></a>
				<?php endif; ?>
			</p>

			<form class="armazon-consent__settings" id="armazon-consent-settings" hidden data-wp-bind--hidden="!state.showSettings" data-wp-on--submit="actions.saveSettings">
				<fieldset>
					<legend><?php esc_html_e( 'Cookie categories', 'armazon-site' ); ?></legend>
					<label class="armazon-consent__category">
						<input type="checkbox" checked disabled>
						<span><strong><?php esc_html_e( 'Necessary', 'armazon-site' ); ?></strong> — <?php esc_html_e( 'Required for the site to work, including remembering this choice. Always active.', 'armazon-site' ); ?></span>
					</label>
					<?php foreach ( $categories as $slug ) : ?>
					<label class="armazon-consent__category">
						<input
							type="checkbox"
							name="armazon-consent-category"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php echo wp_interactivity_data_wp_context( array( 'category' => $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							data-wp-bind--checked="state.isChecked"
							data-wp-on--change="actions.toggleCategory"
						>
						<span><strong><?php echo esc_html( $known[ $slug ]['label'] ); ?></strong> — <?php echo esc_html( $known[ $slug ]['description'] ); ?></span>
					</label>
					<?php endforeach; ?>
				</fieldset>
				<button type="submit" class="wp-element-button armazon-consent__button"><?php esc_html_e( 'Save choices', 'armazon-site' ); ?></button>
			</form>

			<div class="armazon-consent__actions">
				<button type="button" class="wp-element-button armazon-consent__button" data-armazon-consent-action="accept" data-wp-on--click="actions.acceptAll"><?php esc_html_e( 'Accept all', 'armazon-site' ); ?></button>
				<button type="button" class="wp-element-button armazon-consent__button" data-armazon-consent-action="reject" data-wp-on--click="actions.rejectAll"><?php esc_html_e( 'Reject all', 'armazon-site' ); ?></button>
				<button type="button" class="wp-element-button armazon-consent__button" data-armazon-consent-action="configure" aria-controls="armazon-consent-settings" data-wp-bind--aria-expanded="state.showSettings" data-wp-on--click="actions.toggleSettings"><?php esc_html_e( 'Configure', 'armazon-site' ); ?></button>
			</div>
			<?php if ( $powered['show'] ) : ?>
			<p class="armazon-consent__powered">
				<?php if ( '' !== $powered['url'] ) : ?>
				<a href="<?php echo esc_url( $powered['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $powered['text'] ); ?><span class="armazon-consent__sr"> <?php esc_html_e( '(opens in a new tab)', 'armazon-site' ); ?></span></a>
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
					esc_html__( 'Cookie settings: this site declares no optional cookie categories, so there is no notice to reopen and this link is not shown.', 'armazon-site' )
				);
			}
			return '';
		}
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		$label = '' !== $label ? $label : __( 'Cookie settings', 'armazon-site' );
		return sprintf(
			'<p %s><button type="button" class="armazon-consent-link__button" aria-controls="armazon-consent" data-wp-interactive="armazon/consent" data-wp-on--click="actions.open">%s</button></p>',
			get_block_wrapper_attributes( array( 'class' => 'armazon-consent-link' ) ),
			esc_html( $label )
		);
	}

	/**
	 * [armazon_consent_settings label="…"]: lo mismo que el bloque.
	 *
	 * @param array|string $atts Atributos del shortcode.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'label' => '' ), $atts, 'armazon_consent_settings' );
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
