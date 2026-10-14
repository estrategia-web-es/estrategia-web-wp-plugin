<?php
/**
 * EL CARGADOR DE MÓDULOS. Decide qué carpeta de modules/ se carga en cada
 * petición. Si vas a añadir un módulo, esto es lo único que tienes que leer.
 *
 * =========================================================================
 * CÓMO SE AÑADE UN MÓDULO
 * =========================================================================
 *
 * 1. Una carpeta modules/<slug>/ con un fichero modules/<slug>/module.php.
 *    El <slug> es el nombre de la carpeta: minúsculas, cifras y guiones
 *    (`redirects`, `social-links`). Es el identificador que se GUARDA en la
 *    base de datos (opción estrategia_web_modules): no se renombra nunca.
 *
 * 2. module.php empieza por una cabecera como la de un plugin. El cargador
 *    la lee con get_file_data() SIN EJECUTAR el fichero:
 *
 *        <?php
 *        /**
 *         * Module Name: Redirects
 *         * Description: Editable 301/302/410 redirects with CSV import.
 *         * Default:     off
 *         * Requires:    site
 *         *
 *         * (aquí, en castellano, qué hace y cómo se mantiene)
 *         *\/
 *
 *    - Module Name (obligatorio): nombre en INGLÉS. Es el msgid: la pantalla
 *      lo pinta traducido (contexto «module name») y tools/i18n.mjs lo
 *      extrae solo al .pot. Sin Module Name la carpeta se IGNORA.
 *    - Description: una frase en inglés, igual (contexto «module description»).
 *    - Default: `on` u `off`. Cualquier otra cosa cuenta como off. Ver abajo
 *      el caso frontera: decide qué pasa en las webs que YA existen.
 *    - Requires: slugs de otros módulos, separados por comas. Si falta uno
 *      (apagado o inexistente), este no se carga y la pantalla dice por qué.
 *
 * 3. Debajo de la cabecera, el código del módulo: constantes propias si las
 *    necesita (ESTRATEGIA_WEB_<SLUG>_DIR = __DIR__ . '/'), require_once de
 *    sus ficheros (en modules/<slug>/includes/) y sus add_action/add_filter.
 *    Namespace propio: EstrategiaWeb\<Modulo>. Se ejecuta MIENTRAS SE CARGA EL
 *    PLUGIN (antes de plugins_loaded, init y after_setup_theme), así que
 *    todo lo que dependa de WordPress va DENTRO de un gancho, nunca suelto.
 *
 * 4. Lo suyo, dentro de su carpeta:
 *    - bloques en modules/<slug>/blocks/<bloque>/block.json, registrados en
 *      init con register_block_type( __DIR__ . '/blocks/<bloque>' ). Los
 *      textos de editor (edit.js con wp.i18n y dominio estrategia-web) se
 *      traducen solos: includes/class-blocks.php les pasa las traducciones.
 *      Si el bloque usa supports.autoRegister, las etiquetas de sus
 *      atributos van en blocks/<bloque>/labels.php (ver class-blocks.php).
 *    - pruebas en modules/<slug>/tests/*.test.mjs, con «// NIVEL: n — …» en
 *      la primera línea: tests/run.mjs las encuentra solo (no hay lista).
 *      Las del nivel 2/3 hablan con el WordPress de pruebas (puerto 9404);
 *      sus ayudantes comunes están en tests/lib.mjs.
 *    - si al BORRAR el plugin hay que limpiar algo: modules/<slug>/uninstall.php
 *      (lo llama el uninstall.php de la raíz, esté el módulo encendido o no).
 *    - textos: __( 'English text', 'estrategia-web' ). Un solo dominio para
 *      todo el plugin. Después, `node tools/i18n.mjs` y traducir en
 *      languages/estrategia-web-es_ES.po.
 *
 * 5. NO hay registro central que editar. Ni este fichero, ni el principal,
 *    ni la pantalla: el módulo aparece en «Estrategia Web → Módulos» en
 *    cuanto existe la carpeta. Dos personas que añaden dos módulos no tocan
 *    el mismo fichero... salvo tres sitios compartidos que no se pueden
 *    partir y que hay que tocar con cuidado (en el mismo commit que el
 *    módulo): languages/ (el .po, que regenera la herramienta),
 *    wpml-config.xml (WPML solo lee el de la raíz del plugin) y el
 *    CHANGELOG.md.
 *
 * Ejemplo mínimo que funciona: modules/webp/module.php.
 * Ejemplo con bloques, pruebas y desinstalación: modules/forms/module.php.
 *
 * =========================================================================
 * QUÉ SE CARGA, Y DÓNDE SE GUARDA
 * =========================================================================
 *
 * - estrategia_web_modules: array con los slugs ENCENDIDOS. Lo escribe la
 *   pantalla (o `wp option update estrategia_web_modules '["site","forms"]'
 *   --format=json`). Si no existe, valen los «Default» de cada cabecera: una
 *   web nueva no escribe nada hasta que alguien toca la pantalla.
 * - estrategia_web_modules_known: los slugs que existían la última vez que se
 *   guardó la anterior. Se escribe SOLA al guardarla (gancho de abajo).
 * - Un módulo apagado no carga NADA: ni su module.php (solo se lee su
 *   cabecera como texto), ni sus clases, ni sus bloques, ni sus assets.
 *   Lo que tenga guardado (opciones, tablas, entradas) se queda donde está:
 *   apagar no borra; volver a encenderlo lo recupera tal cual.
 *
 * CASO FRONTERA, con nombre: llega un módulo NUEVO a una web que ya había
 * guardado su lista. Ejemplo real que va a pasar: «uploads» (las subidas de
 * imágenes, hoy un MU-plugin en Lavandería) con Default: on, desplegado a
 * Lavandería, que ya tiene estrategia_web_modules guardada sin él.
 *   - Si el slug NO está en _known (la web no lo ha visto nunca), se aplica
 *     su Default: con «on» se enciende solo al desplegar. Es lo que se quiere
 *     para algo que sustituye a lo que ya hacía la web.
 *   - Si está en _known y no en la lista, alguien lo APAGÓ: se respeta.
 *   - Por eso Default: on es una decisión sobre TODAS las webs desplegadas,
 *     no solo las nuevas. Lo que cambia algo visible (un aviso, un bloque que
 *     se pinta solo, redirecciones) nace con Default: off.
 *
 * Dependencias: se resuelven en cada carga, no al guardar. Se quita de la
 * lista todo módulo con un Requires que no esté, y se repite hasta que no
 * cambie nada (A necesita B, B necesita C apagado: caen B y A). Se cargan en
 * orden de dependencias y, entre iguales, alfabético.
 *
 * =========================================================================
 * LAS TRAMPAS (las que mordieron al montarlo)
 * =========================================================================
 *
 * - El gancho de ACTIVACIÓN del plugin (estrategia_web_activate) solo salta
 *   al activar el PLUGIN en «Plugins», no al encender un módulo en la
 *   pantalla: cuando se guarda la pantalla, el módulo recién encendido
 *   todavía no está cargado en esa petición. Así que lo que un módulo
 *   necesita para funcionar (crear tablas, programar un cron) se hace
 *   IDEMPOTENTE EN CADA CARGA, como forms: Migrations::maybe_run en
 *   plugins_loaded y Cron::schedule en init. estrategia_web_activate queda
 *   para lo que solo tiene sentido al activar el plugin entero.
 * - Al guardar la lista se borran las reglas de reescritura (rewrite_rules):
 *   un módulo que registra tipos de contenido o rutas cambia las URL, y
 *   WordPress las regenera solo en la petición siguiente. No hace falta que
 *   cada módulo lo haga.
 * - get_file_data() solo lee los primeros 8 KB del fichero y busca la clave
 *   como «Clave:» al principio de una línea de comentario (sin distinguir
 *   mayúsculas). Una cabecera detrás de 8 KB de código, o una clave mal
 *   escrita («Modul Name:», «Module-Name:»), no se ve: la carpeta se ignora
 *   SIN AVISAR, y en la pantalla simplemente no sale. Por eso la cabecera va
 *   la primera y tests/modules.test.mjs comprueba que TODAS las carpetas de
 *   modules/ se leen con nombre (vista en rojo con «Modul Name:»). Ojo
 *   también con lo que escribas debajo: una línea de la documentación que
 *   empiece por «Default:» o «Requires:» antes que la de la cabecera la
 *   sustituye (gana la primera).
 * - Un módulo NO llama a clases de otro módulo sin declararlo en Requires:
 *   si el otro está apagado, la clase no existe y es un error fatal. Si la
 *   relación es opcional, class_exists() antes (ver modules/site, que no sabe
 *   si consent está).
 *
 * Prueba: tests/modules.test.mjs (nivel 1: cabeceras y resolución) y
 * tests/modules-page.test.mjs (nivel 2: apagar un módulo quita su bloque).
 */

namespace EstrategiaWeb;

defined( 'ABSPATH' ) || exit;

final class Modules {

	/** Slugs encendidos (lo que guarda la pantalla). */
	public const OPTION = 'estrategia_web_modules';

	/** Slugs que existían la última vez que se guardó OPTION (ver el caso frontera). */
	public const KNOWN_OPTION = 'estrategia_web_modules_known';

	/** Cabecera de module.php → clave interna. */
	public const HEADERS = array(
		'name'        => 'Module Name',
		'description' => 'Description',
		'default'     => 'Default',
		'requires'    => 'Requires',
	);

	/** @var array<string, array{name: string, description: string, default: bool, requires: string[], file: string}>|null */
	private static ?array $available = null;

	/** @var string[] Los que se cargaron en esta petición, en orden. */
	private static array $loaded = array();

	/** @var array<string, string[]> slug => requisitos que faltan (para la pantalla). */
	private static array $blocked = array();

	/** Carpeta de los módulos. */
	public static function dir(): string {
		return ESTRATEGIA_WEB_DIR . 'modules/';
	}

	/**
	 * Los módulos que hay en disco, leídos de su cabecera.
	 *
	 * @return array<string, array{name: string, description: string, default: bool, requires: string[], file: string}>
	 */
	public static function available(): array {
		if ( null === self::$available ) {
			self::$available = array();
			foreach ( glob( self::dir() . '*/module.php' ) ?: array() as $file ) {
				$module = self::read_header( $file );
				if ( null !== $module ) {
					self::$available[ basename( dirname( $file ) ) ] = $module;
				}
			}
			ksort( self::$available );
		}
		return self::$available;
	}

	/**
	 * Lee la cabecera de un module.php. null si la carpeta no es un módulo
	 * válido (slug con caracteres raros o sin Module Name).
	 *
	 * @return array{name: string, description: string, default: bool, requires: string[], file: string}|null
	 */
	public static function read_header( string $file ): ?array {
		$slug = basename( dirname( $file ) );
		if ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) ) {
			return null;
		}
		$data = get_file_data( $file, self::HEADERS );
		if ( '' === trim( (string) $data['name'] ) ) {
			return null;
		}
		return array(
			'name'        => trim( (string) $data['name'] ),
			'description' => trim( (string) $data['description'] ),
			'default'     => 'on' === strtolower( trim( (string) $data['default'] ) ),
			'requires'    => array_values( array_filter( array_map( 'trim', explode( ',', (string) $data['requires'] ) ) ) ),
			'file'        => $file,
		);
	}

	/**
	 * Lo que la web quiere encendido, antes de mirar dependencias.
	 *
	 * Función pura (sin WordPress) para poder probarla en el nivel 1.
	 *
	 * @param array<string, array{default: bool}> $available Módulos en disco.
	 * @param mixed                               $saved     La opción OPTION (null si no existe).
	 * @param mixed                               $known     La opción KNOWN_OPTION (null si no existe).
	 * @return string[]
	 */
	public static function wanted( array $available, $saved, $known ): array {
		$defaults = array_keys( array_filter( $available, static fn( $m ) => ! empty( $m['default'] ) ) );
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}
		// Sin _known (la lista se escribió a mano en la base de datos), la lista manda entera.
		$known  = is_array( $known ) ? $known : array_keys( $available );
		$wanted = array_intersect( array_map( 'strval', $saved ), array_keys( $available ) );
		foreach ( $defaults as $slug ) {
			if ( ! in_array( $slug, $known, true ) ) {
				$wanted[] = $slug; // Nuevo para esta web y encendido por defecto: el caso frontera.
			}
		}
		return array_values( array_unique( $wanted ) );
	}

	/**
	 * Quita los que no tienen sus requisitos y ordena por dependencias.
	 *
	 * Función pura, como wanted().
	 *
	 * @param array<string, array{requires: string[]}> $available Módulos en disco.
	 * @param string[]                                 $wanted    Lo que la web quiere.
	 * @return array{0: string[], 1: array<string, string[]>} [ cargables en orden, bloqueados => lo que les falta ]
	 */
	public static function resolve( array $available, array $wanted ): array {
		$active  = array_values( array_intersect( array_keys( $available ), $wanted ) );
		$blocked = array();
		do {
			$changed = false;
			foreach ( $active as $i => $slug ) {
				$missing = array_values( array_diff( $available[ $slug ]['requires'], $active ) );
				if ( $missing ) {
					$blocked[ $slug ] = $missing;
					unset( $active[ $i ] );
					$changed = true;
				}
			}
			$active = array_values( $active );
		} while ( $changed );

		// Orden: cada uno después de lo que necesita; entre iguales, alfabético.
		$ordered = array();
		$seen    = array(); // Visitados: un ciclo A↔B no da vueltas sin fin.
		$visit   = static function ( string $slug ) use ( &$visit, &$ordered, &$seen, $available ): void {
			if ( isset( $seen[ $slug ] ) ) {
				return;
			}
			$seen[ $slug ] = true;
			foreach ( $available[ $slug ]['requires'] as $required ) {
				$visit( $required ); // Todos están en $active: si no, ya estaría bloqueado.
			}
			$ordered[] = $slug;
		};
		sort( $active );
		foreach ( $active as $slug ) {
			$visit( $slug );
		}
		return array( $ordered, $blocked );
	}

	/** Carga los módulos encendidos. La llama Plugin::boot(), una vez. */
	public static function load(): void {
		$available = self::available();
		list( $active, self::$blocked ) = self::resolve(
			$available,
			self::wanted( $available, get_option( self::OPTION, null ), get_option( self::KNOWN_OPTION, null ) )
		);
		foreach ( $active as $slug ) {
			require_once $available[ $slug ]['file'];
			self::$loaded[] = $slug;
		}

		add_action( 'add_option_' . self::OPTION, array( self::class, 'saved' ) );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'saved' ) );

		/**
		 * Los módulos ya están cargados (antes de plugins_loaded).
		 *
		 * @param string[] $loaded Slugs cargados, en orden.
		 */
		do_action( 'estrategia_web_modules_loaded', self::$loaded );
	}

	/** Si un módulo se cargó en esta petición. */
	public static function is_active( string $slug ): bool {
		return in_array( $slug, self::$loaded, true );
	}

	/** @return string[] */
	public static function loaded(): array {
		return self::$loaded;
	}

	/** @return array<string, string[]> */
	public static function blocked(): array {
		return self::$blocked;
	}

	/** Limpia lo que llega a la opción: solo slugs que existen. */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array_values( array_intersect( array_keys( self::available() ), array_map( 'strval', $value ) ) );
	}

	/** Al guardar la lista: apunta qué módulos había y que se regeneren las URL. */
	public static function saved(): void {
		update_option( self::KNOWN_OPTION, array_keys( self::available() ), false );
		delete_option( 'rewrite_rules' );
	}
}
