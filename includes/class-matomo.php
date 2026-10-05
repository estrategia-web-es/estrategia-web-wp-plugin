<?php
/**
 * Matomo (Armazón, fila 16): el código de seguimiento en el <head> de la web,
 * con la URL, el id del sitio y el modo de la opción `armazon_site`
 * (`matomo_url`, `matomo_site_id`, `matomo_mode`; ver Settings).
 *
 * Los dos modos:
 * - `cookieless` (por defecto, el de la fila 16): `disableCookies`. Matomo no
 *   pone ninguna cookie y no hay nada que preguntar: el aviso de cookies
 *   (includes/class-consent.php) ni siquiera sale si no hay otra categoría.
 * - `hybrid`: el rastreo empieza SIN cookies (`requireCookieConsent`: Matomo
 *   manda las visitas pero no escribe cookies) y, cuando el visitante acepta
 *   «statistics» en el aviso de cookies, se llama a
 *   `rememberCookieConsentGiven` (Matomo guarda su propia cookie
 *   mtm_cookie_consent y a partir de ahí usa _pk_id/_pk_ses); si la retira,
 *   `forgetCookieConsentGiven` (borra su consentimiento y sus cookies).
 *   Referencia: https://developer.matomo.org/guides/tracking-consent
 *   OJO: la legalidad del híbrido (medir sin cookies ANTES de preguntar) no
 *   está verificada; el criterio de la CNIL para la exención de consentimiento
 *   es más estrecho. Por eso no es el modo por defecto.
 *
 * Cómo se entera del consentimiento (contrato del aviso, que desde 0.5.0 es de
 * este mismo plugin: includes/class-consent.php y blocks/consent/view.js; antes
 * vivía en el tema padre): el evento «armazon:consent» en document
 * (detail.accepted = categorías aceptadas), que se lanza al arrancar si ya
 * había elección y en cada cambio, y window.armazonConsent.get() (null = sin
 * elegir). Se escuchan LAS DOS cosas: el módulo del aviso puede llegar antes
 * o después que este script. Se habla con él SOLO por ese contrato, no
 * llamando a Consent: así sigue valiendo con un tema padre viejo que aún
 * traiga su propio aviso (Consent::active() = false).
 * - En modo híbrido el plugin DECLARA la categoría «statistics» con el filtro
 *   `armazon_consent_categories` (prioridad 99), para que el aviso salga sin
 *   que el tema tenga que acordarse.
 * - Desde 0.5.0 el aviso no depende del tema: con cualquier tema hay aviso y
 *   evento. (Hasta 0.4.0, sin el tema padre el híbrido se quedaba sin cookies
 *   para siempre.)
 *
 * Quién NO se mide: el escritorio, el editor, las vistas previas y los
 * usuarios con sesión que pueden editar (`edit_posts`: autores y arriba). Es
 * lo habitual (el plugin oficial de Matomo para WordPress tampoco mide a los
 * administradores por defecto) y evita que la gente de la web infle las
 * cifras. Una web que quiera otra cosa: filtro `armazon_site_matomo_track`.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien toque el plugin; la prueba es
 *   tests/matomo.test.mjs (nivel 2) y tests/matomo-browser.test.mjs (nivel 3,
 *   con el matomo.js real de tests/fixtures/ servido por una ruta falsa).
 * - Un modo nuevo: Settings::MATOMO_MODES + script() + la pantalla + README.
 *   Quitar uno es versión mayor (ver la trampa de Settings).
 * - Caso frontera: la categoría se retira en el aviso en una página y el
 *   visitante sigue navegando. El aviso solo RECARGA si había un
 *   <script type="text/plain" data-armazon-consent> ya ejecutado; este código
 *   no lo es, así que no hay recarga: se llama a forgetCookieConsentGiven al
 *   momento y basta.
 * - La trampa 1: mtm_cookie_consent dura 30 años y la elección del aviso 12
 *   meses. Cuando la del aviso caduca (o cambia su revisión), get() da null y
 *   NO llega evento: por eso, al cargar la página, si el aviso está y dice
 *   «sin elegir», se llama a forgetCookieConsentGiven. Sin eso Matomo
 *   seguiría con cookies con un consentimiento que ya no existe.
 *   «Al cargar» NO es window.load: el aviso hidrata cuando puede, y a veces
 *   DESPUÉS de load (medido el 05-10-2026 al probar con el aviso real: 1 de
 *   cada 3 cargas; la prueba simulada de 0.3.0 no podía verlo). Por eso se
 *   escucha también «armazon:consent-ready», que el aviso lanza una vez al
 *   arrancar, con elección o sin ella (0.5.0).
 * - La trampa 2: un hijo que declara categorías con
 *   `fn() => array( 'maps' )` (sin mirar lo que recibe) PISA la de este
 *   plugin si corre después. Por eso este filtro va a prioridad 99. Si un
 *   hijo la necesita aún más tarde, que añada en vez de sustituir.
 * - La trampa 3: el código va en línea en el <head> y es el mismo para todos
 *   los visitantes, así que una caché de página no rompe nada: la decisión
 *   de cookies se toma en el navegador. El filtro de quién se mide sí depende
 *   de la sesión, pero a los usuarios con sesión no se les sirve caché.
 * - «Sin IP completa» (fila 16) NO es de aquí: se configura en el servidor de
 *   Matomo (Anonimizar IP), no en el código de seguimiento.
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Matomo {

	/** Categoría del aviso de cookies que da el consentimiento en modo híbrido. */
	public const CONSENT_CATEGORY = 'statistics';

	public static function boot(): void {
		add_action( 'wp_head', array( self::class, 'print' ), 20 );
		add_filter( 'armazon_consent_categories', array( self::class, 'consent_categories' ), 99 );
	}

	/**
	 * La configuración lista para usar, o null si falta la URL o el id.
	 *
	 * @return array{url: string, site_id: int, mode: string}|null
	 */
	public static function config(): ?array {
		$url     = (string) Settings::get( 'matomo_url' );
		$site_id = absint( Settings::get( 'matomo_site_id' ) );
		if ( '' === $url || 0 === $site_id ) {
			return null;
		}
		$mode = (string) Settings::get( 'matomo_mode' );
		return array(
			'url'     => trailingslashit( $url ),
			'site_id' => $site_id,
			'mode'    => in_array( $mode, Settings::MATOMO_MODES, true ) ? $mode : Settings::MATOMO_MODES[0],
		);
	}

	/** Si en esta petición se mide. */
	public static function should_track(): bool {
		$track = ! is_admin() && ! is_preview() && ! is_customize_preview() && ! current_user_can( 'edit_posts' );
		/**
		 * Decide si esta petición se mide (por defecto: no en el escritorio, ni
		 * en vistas previas, ni para quien puede editar).
		 *
		 * @param bool $track Lo decidido por el plugin.
		 */
		return (bool) apply_filters( 'armazon_site_matomo_track', $track );
	}

	/** En modo híbrido, el aviso tiene que preguntar por las estadísticas. */
	public static function consent_categories( mixed $categories ): array {
		$categories = (array) $categories;
		$config     = self::config();
		if ( $config && 'hybrid' === $config['mode'] && ! in_array( self::CONSENT_CATEGORY, $categories, true ) ) {
			$categories[] = self::CONSENT_CATEGORY;
		}
		return $categories;
	}

	public static function print(): void {
		$config = self::config();
		if ( ! $config || ! self::should_track() ) {
			return;
		}
		wp_print_inline_script_tag( self::script( $config ), array( 'id' => 'armazon-site-matomo' ) );
	}

	/** El JS de seguimiento. Sin textos de pantalla: nada que traducir. */
	public static function script( array $config ): string {
		$url      = wp_json_encode( $config['url'] );
		$site_id  = wp_json_encode( (string) $config['site_id'] );
		$category = wp_json_encode( self::CONSENT_CATEGORY );
		$hybrid   = 'hybrid' === $config['mode'];
		$first    = $hybrid ? "_paq.push(['requireCookieConsent']);" : "_paq.push(['disableCookies']);";

		$js = <<<JS
var _paq = window._paq = window._paq || [];
{$first}
_paq.push(['trackPageView']);
_paq.push(['enableLinkTracking']);
(function () {
	var u = {$url};
	_paq.push(['setTrackerUrl', u + 'matomo.php']);
	_paq.push(['setSiteId', {$site_id}]);
	var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
	g.async = true; g.src = u + 'matomo.js'; s.parentNode.insertBefore(g, s);
})();
JS;
		if ( ! $hybrid ) {
			return $js;
		}
		// Híbrido: sigue al aviso de cookies (por su contrato JS). Ver la cabecera (las dos vías y la trampa 1).
		$js .= <<<JS

(function () {
	var given = null;
	function sync(accepted) {
		var yes = Array.isArray(accepted) && accepted.indexOf({$category}) !== -1;
		if (yes === given) { return; }
		given = yes;
		window._paq.push([yes ? 'rememberCookieConsentGiven' : 'forgetCookieConsentGiven']);
	}
	document.addEventListener('armazon:consent', function (e) { sync(e.detail && e.detail.accepted); });
	function current() {
		if (given === null && window.armazonConsent && typeof window.armazonConsent.get === 'function') {
			var choice = window.armazonConsent.get();
			sync(choice || []);
		}
	}
	current();
	window.addEventListener('load', current);
	document.addEventListener('armazon:consent-ready', current);
})();
JS;
		return $js;
	}
}
