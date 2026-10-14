<?php
/**
 * Etiquetas de Google (GA4, Ads, Tag Manager) detrás del aviso de cookies,
 * con Consent Mode v2.
 *
 * Qué se imprime, y dónde: UN script en línea en el <head>, en wp_head con
 * prioridad 1 (antes que cualquier otra etiqueta, también la de Matomo, 20),
 * id «estrategia-web-google-tags». Nada si los tres campos están vacíos o si
 * la petición no se mide (escritorio, vistas previas, quien puede editar:
 * filtro estrategia_web_google_tags_track, como Matomo). El script:
 * 1. Crea dataLayer y gtag() y manda gtag('consent', 'default', …) con TODO
 *    «denied» (salvo security_storage) ANTES de que exista ninguna etiqueta.
 *    Es lo que pide Consent Mode v2: el «default» tiene que ir primero.
 * 2. Escucha al aviso (window.estrategiaWebConsent y los eventos
 *    «estrategia-web:consent» y «estrategia-web:consent-ready»: las dos vías,
 *    por la trampa del orden de carga que cuenta el aviso) y en cada elección
 *    manda gtag('consent', 'update', …) con lo aceptado:
 *       analytics_storage                          ← statistics (Analítica)
 *       ad_storage, ad_user_data, ad_personalization ← marketing (Publicidad)
 *       functionality_storage, personalization_storage ← preferences
 * 3. Carga los scripts de Google (gtag/js?id=… con la config de GA4 y Ads,
 *    gtm.js?id=…) según el MODO:
 *    - «basic» (por defecto): SOLO cuando hay aceptada Analítica o
 *      Publicidad. Antes de eso no sale ni una petición a Google: ni la IP del
 *      visitante. Es lo que pide la AEPD (nada de terceros sin consentir).
 *    - «advanced» (opción): se cargan ya al abrir la página, con todo
 *      «denied»; Google no pone cookies y manda pings sin cookies que luego
 *      «modeliza». Se gana medición, pero esos pings SÍ salen antes de
 *      consentir, con la IP. La legalidad en España está discutida (la AEPD
 *      no lo ha avalado): solo si el cliente lo decide sabiéndolo.
 * 4. Si se retira Analítica o Publicidad con la página abierta: 'update' a
 *    denied y borra las cookies de primera parte de Google de esa categoría
 *    (_ga, _ga_*, _gid, _gat* / _gcl_*, _gac_*), en el dominio y sus padres.
 *    El script de Google ya cargado se queda hasta la siguiente página, pero
 *    con el consentimiento denegado no escribe.
 * 5. Conversión de formulario (opcional): con «Etiqueta de conversión»
 *    (AW-123/abc) y Publicidad aceptada, cada envío correcto de un
 *    formulario del módulo forms (evento «estrategia-web:form-sent», solo el
 *    envío con JavaScript) manda gtag('event', 'conversion', {send_to}).
 *
 * Categorías del aviso: el módulo DECLARA con el filtro
 * estrategia_web_consent_categories (prioridad 99, añadiendo, como Matomo)
 * statistics si hay GA4, marketing si hay Ads, y las dos con GTM. Depende
 * solo de la CONFIGURACIÓN, nunca de quién visita: la revisión del aviso
 * tiene que ser la misma para todos (con caché de página, más aún).
 *
 * Sin <noscript> de GTM: un iframe a Google sin JavaScript no puede esperar
 * al consentimiento. Quien navega sin JS no se mide.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien toque la analítica. Pruebas en
 *   modules/google-tags/tests/ (google-tags.test.mjs nivel 2,
 *   google-tags-browser.test.mjs nivel 3).
 * - Un campo nuevo: FIELDS (patrón), defaults(), sanitize(), la pantalla y
 *   config_for_js(). Los patrones van también al esquema REST (400 si no
 *   casan).
 * - Caso frontera: el cliente ya tiene Site Kit u otro plugin que pinta
 *   gtag. Habría dos dataLayer/gtag y dos «default»: se usa UNO de los dos.
 *   Este no detecta al otro.
 * - La trampa: gtag() tiene que ser una función GLOBAL que empuja `arguments`
 *   (no un array): gtag.js solo entiende objetos Arguments en dataLayer. Un
 *   gtag = (...a) => dataLayer.push(a) parece igual y Google lo IGNORA sin
 *   error. Por eso el script es el de Google tal cual, en ES5.
 */

namespace EstrategiaWeb\GoogleTags;

defined( 'ABSPATH' ) || exit;

final class Google_Tags {

	public const OPTION = 'estrategia_web_google_tags';

	/** Campo => patrón (vacío siempre vale: «no cargar»). */
	public const FIELDS = array(
		'ga4'            => '^G-[A-Z0-9]{4,20}$',
		'ads'            => '^AW-[0-9]{6,15}$',
		'ads_form_label' => '^AW-[0-9]{6,15}/[A-Za-z0-9_-]{4,40}$',
		'gtm'            => '^GTM-[A-Z0-9]{4,12}$',
	);

	public const MODES = array( 'basic', 'advanced' );

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'wp_head', array( self::class, 'print' ), 1 );
		add_filter( 'estrategia_web_consent_categories', array( self::class, 'consent_categories' ), 99 );
	}

	public static function defaults(): array {
		return array(
			'ga4'            => '',
			'ads'            => '',
			'ads_form_label' => '',
			'gtm'            => '',
			'mode'           => 'basic',
		);
	}

	public static function register(): void {
		$properties = array(
			'mode' => array(
				'type' => 'string',
				'enum' => self::MODES,
			),
		);
		foreach ( self::FIELDS as $field => $pattern ) {
			$properties[ $field ] = array(
				'type'    => 'string',
				'pattern' => '(' . $pattern . ')|^$',
			);
		}
		register_setting(
			'estrategia_web_google_tags',
			self::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'Google tags: GA4, Ads and Tag Manager IDs and the loading mode.', 'estrategia-web' ),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => $properties,
					),
				),
			)
		);
	}

	/** Lo guardado, completo y limpio. */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	/** Fusiona con lo guardado y valida. Un id que no casa se descarta (y la pantalla lo dice). */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$saved = get_option( self::OPTION, array() );
		$out   = self::clean( array_merge( is_array( $saved ) ? $saved : array(), $value ) );
		foreach ( array_keys( self::FIELDS ) as $field ) {
			$raw = strtoupper( trim( (string) ( $value[ $field ] ?? '' ) ) );
			if ( 'ads_form_label' === $field ) {
				$raw = trim( (string) ( $value[ $field ] ?? '' ) ); // La etiqueta distingue mayúsculas.
			}
			if ( '' !== $raw && $out[ $field ] !== $raw && function_exists( 'add_settings_error' ) ) {
				/* translators: %s: the value that was not saved. */
				add_settings_error( self::OPTION, 'estrategia-web-google-' . $field, sprintf( __( '«%s» does not look like a valid ID and was not saved.', 'estrategia-web' ), $raw ) );
			}
		}
		return $out;
	}

	/** Solo valores válidos: lo que no casa con su patrón, vacío. */
	public static function clean( array $value ): array {
		$out = self::defaults();
		foreach ( self::FIELDS as $field => $pattern ) {
			$raw = trim( (string) ( $value[ $field ] ?? '' ) );
			if ( 'ads_form_label' !== $field ) {
				$raw = strtoupper( $raw );
			}
			$out[ $field ] = preg_match( '#' . $pattern . '#', $raw ) ? $raw : '';
		}
		$out['mode'] = in_array( $value['mode'] ?? '', self::MODES, true ) ? $value['mode'] : 'basic';
		return $out;
	}

	/** Si hay algo que cargar. */
	public static function configured( ?array $settings = null ): bool {
		$s = $settings ?? self::settings();
		return '' !== $s['ga4'] || '' !== $s['ads'] || '' !== $s['gtm'];
	}

	/** Categorías que pide cada etiqueta (ver la cabecera). Solo de la configuración. */
	public static function consent_categories( mixed $categories ): array {
		$categories = (array) $categories;
		$s          = self::settings();
		$needed     = array();
		if ( '' !== $s['ga4'] || '' !== $s['gtm'] ) {
			$needed[] = 'statistics';
		}
		if ( '' !== $s['ads'] || '' !== $s['gtm'] ) {
			$needed[] = 'marketing';
		}
		return array_values( array_unique( array_merge( $categories, $needed ) ) );
	}

	/** Si en esta petición se mide (como Matomo). */
	public static function should_track(): bool {
		$track = ! is_admin() && ! is_preview() && ! is_customize_preview() && ! current_user_can( 'edit_posts' );
		/**
		 * Decide si esta petición carga las etiquetas de Google.
		 *
		 * @param bool $track Lo decidido por el plugin.
		 */
		return (bool) apply_filters( 'estrategia_web_google_tags_track', $track );
	}

	public static function print(): void {
		$s = self::settings();
		if ( ! self::configured( $s ) || ! self::should_track() ) {
			return;
		}
		wp_print_inline_script_tag( self::script( $s ), array( 'id' => 'estrategia-web-google-tags' ) );
	}

	/** Lo que necesita el script del navegador. */
	public static function config_for_js( array $s ): array {
		$form = '';
		if ( '' !== $s['ads_form_label'] && '' !== $s['ads'] && str_starts_with( $s['ads_form_label'], $s['ads'] . '/' ) ) {
			$form = $s['ads_form_label'];
		}
		return array(
			'ga4'      => $s['ga4'],
			'ads'      => $s['ads'],
			'gtm'      => $s['gtm'],
			'form'     => $form,
			'advanced' => 'advanced' === $s['mode'],
		);
	}

	/** El JS (ES5, sin textos de pantalla). Ver la cabecera, puntos 1-5. */
	public static function script( array $s ): string {
		$config = wp_json_encode( self::config_for_js( $s ) );
		return <<<JS
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', {
	ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied',
	analytics_storage: 'denied', functionality_storage: 'denied',
	personalization_storage: 'denied', security_storage: 'granted',
	wait_for_update: 500
});
gtag('set', 'ads_data_redaction', true);
(function () {
	var c = {$config};
	var loaded = false, now = { stats: false, ads: false, prefs: false }, known = false;
	function add(src) { var s = document.createElement('script'); s.async = true; s.src = src; document.head.appendChild(s); }
	function load() {
		if (loaded) { return; }
		loaded = true;
		var first = c.ga4 || c.ads;
		if (first) {
			add('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(first));
			gtag('js', new Date());
			if (c.ga4) { gtag('config', c.ga4); }
			if (c.ads) { gtag('config', c.ads); }
		}
		if (c.gtm) {
			window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
			add('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(c.gtm));
		}
	}
	function forget(re) {
		document.cookie.split(';').forEach(function (pair) {
			var name = pair.split('=')[0].trim();
			if (!re.test(name)) { return; }
			var parts = location.hostname.split('.');
			for (var i = 0; i < parts.length - 1; i++) {
				document.cookie = name + '=; Max-Age=0; Path=/; Domain=.' + parts.slice(i).join('.');
			}
			document.cookie = name + '=; Max-Age=0; Path=/';
		});
	}
	function g(yes) { return yes ? 'granted' : 'denied'; }
	function sync(accepted) {
		var list = Array.isArray(accepted) ? accepted : [];
		var next = { stats: list.indexOf('statistics') !== -1, ads: list.indexOf('marketing') !== -1, prefs: list.indexOf('preferences') !== -1 };
		if (known && next.stats === now.stats && next.ads === now.ads && next.prefs === now.prefs) { return; }
		var before = now;
		now = next;
		known = true;
		gtag('consent', 'update', {
			analytics_storage: g(next.stats),
			ad_storage: g(next.ads), ad_user_data: g(next.ads), ad_personalization: g(next.ads),
			functionality_storage: g(next.prefs), personalization_storage: g(next.prefs)
		});
		if (before.stats && !next.stats) { forget(/^(_ga|_ga_.*|_gid|_gat.*)$/); }
		if (before.ads && !next.ads) { forget(/^(_gcl_.*|_gac_.*)$/); }
		if (next.stats || next.ads) { load(); }
	}
	if (c.advanced) { load(); }
	document.addEventListener('estrategia-web:consent', function (e) { sync(e.detail && e.detail.accepted); });
	document.addEventListener('estrategia-web:consent-ready', function (e) { if (e.detail && e.detail.accepted) { sync(e.detail.accepted); } });
	if (window.estrategiaWebConsent && typeof window.estrategiaWebConsent.get === 'function' && window.estrategiaWebConsent.get()) {
		sync(window.estrategiaWebConsent.get());
	}
	document.addEventListener('estrategia-web:form-sent', function () {
		if (c.form && now.ads && loaded) { gtag('event', 'conversion', { send_to: c.form }); }
	});
})();
JS;
	}
}
