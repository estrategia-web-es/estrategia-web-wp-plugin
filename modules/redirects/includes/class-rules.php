<?php
/**
 * Lo PURO del módulo: normalizar un origen, las claves con que se busca una
 * petición, leer el CSV y validar una regla contra las demás. Sin WordPress
 * (ni una función wp_*, ni $wpdb): así lo prueba el nivel 1 con el PHP de la
 * máquina (tests/php/pure.php), con el CSV de verdad de Lavandería.
 *
 * =========================================================================
 * EL CRITERIO PARA CASAR (lo que decide si una petición casa con un origen)
 * =========================================================================
 *
 * Origen y petición se reducen a la misma CLAVE, y casan si la clave es igual:
 *
 *   1. Fuera el esquema y el dominio (https://ejemplo.com/x → /x) y el #.
 *   2. La ruta se decodifica UNA vez (%xx → carácter; %253F queda en %3F).
 *   3. Si tras decodificar la ruta lleva un «?» (las URL viejas de WordPress
 *      exportadas a estático: /index.html%3Fp=6981.html), lo que va detrás
 *      se trata como query. Así /index.html%3Fp=6981.html y
 *      /index.html?p=6981.html son la misma clave.
 *   4. Barras repetidas a una; sin barra final (/contacto/ = /contacto); la
 *      raíz sigue siendo «/». Todo en minúsculas (las rutas de WordPress lo
 *      son; «/Contacto/» casa con «/contacto/»), también los valores de la
 *      query.
 *   5. Query: page_id es lo mismo que p (WordPress resuelve las dos por ID),
 *      parámetros ordenados por nombre, sin vacíos.
 *
 * La query de la PETICIÓN que no forma parte del origen se ignora: el origen
 * /contacto/ casa con /contacto/?utm_source=x, y /?p=6981 con
 * /?p=6981&lang=es. Por eso una petición se busca con varias claves
 * (candidates()), de más concreta a menos: la query entera, cada parámetro
 * suelto, y la ruta sola. Caso frontera con nombre: un origen con DOS
 * parámetros (/?a=1&b=2) casa con exactamente esos dos, no con a=1&b=2&c=3.
 * Las URL de WordPress que hay que redirigir llevan uno (p o page_id).
 *
 * La trampa que mordió: decodificar ANTES de partir por «?» convierte
 * /index.html%3Fp=6981.html en ruta + query; partir antes y decodificar
 * después deja el «?» dentro de la ruta. Por eso se parte dos veces (paso 3):
 * así las dos formas acaban igual, venga el «?» codificado o no.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Rules {

	public const TYPES = array( 301, 302, 410 );

	/** Cuántos saltos se siguen buscando un bucle (A→B→C→…→A). */
	public const MAX_HOPS = 20;

	/** Parámetros de la petición que se miran como mucho (una query de 500 no genera 500 claves). */
	public const MAX_PARAMS = 10;

	/**
	 * Ruta y parámetros normalizados de una URL, ruta u origen.
	 *
	 * @param string $url  Lo que sea: «/x/», «/x?p=1», «https://dominio/x», «/x%3Fp=1».
	 * @param string $base Ruta de la instalación si WordPress vive en una subcarpeta («/blog»); se quita.
	 * @return array{0: string, 1: array<string, string>} [ ruta, parámetros ordenados ]
	 */
	public static function split( string $url, string $base = '' ): array {
		$url = trim( $url );
		if ( preg_match( '#^(?:[a-z][a-z0-9+.\-]*:)?//[^/?\#]*#i', $url, $m ) ) {
			$url = substr( $url, strlen( $m[0] ) );
		}
		$url = explode( '#', $url, 2 )[0];

		list( $path, $query ) = array_pad( explode( '?', $url, 2 ), 2, '' );
		$path = rawurldecode( $path );
		if ( false !== strpos( $path, '?' ) ) { // Paso 3: el %3F de las URL viejas.
			list( $path, $more ) = explode( '?', $path, 2 );
			$query = '' === $query ? $more : $more . '&' . $query;
		}

		$path = '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );
		$base = '/' . trim( self::lower( $base ), '/' );
		$path = self::lower( $path );
		if ( '/' !== $base && ( $path === $base || 0 === strpos( $path, $base . '/' ) ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $base ) ), '/' );
		}
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		$params = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			list( $k, $v ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$k = self::lower( trim( urldecode( $k ) ) );
			$v = self::lower( trim( urldecode( $v ) ) );
			if ( '' === $k || '' === $v ) {
				continue;
			}
			if ( 'page_id' === $k ) {
				$k = 'p';
			}
			$params[ $k ] = $v; // Repetido (p=1&p=2): gana el último, como en $_GET.
		}
		ksort( $params, SORT_STRING );
		return array( $path, $params );
	}

	/** La clave con que se guarda un origen (y se busca). '' si no hay nada que casar. */
	public static function key( string $source, string $base = '' ): string {
		if ( '' === trim( $source ) ) {
			return '';
		}
		list( $path, $params ) = self::split( $source, $base );
		return self::join( $path, $params );
	}

	/**
	 * Claves con que se busca una PETICIÓN, de más concreta a menos.
	 *
	 * @return string[]
	 */
	public static function candidates( string $request_uri, string $base = '' ): array {
		list( $path, $params ) = self::split( $request_uri, $base );
		$params = array_slice( $params, 0, self::MAX_PARAMS, true );
		$keys   = array();
		if ( $params ) {
			$keys[] = self::join( $path, $params );
			if ( isset( $params['p'] ) ) {
				$keys[] = self::join( $path, array( 'p' => $params['p'] ) ); // El caso que importa, primero.
			}
			foreach ( $params as $k => $v ) {
				$keys[] = self::join( $path, array( $k => $v ) );
			}
		}
		$keys[] = $path;
		return array_values( array_unique( $keys ) );
	}

	/** Hash de una clave: lo que lleva el índice único (una clave puede pasar de 191 caracteres). */
	public static function hash( string $key ): string {
		return md5( $key );
	}

	/**
	 * Lee un CSV de redirecciones. Acepta el de Lavandería
	 * (origen,destino,tipo,motivo,revisar) y lo que exporta este módulo
	 * (con activa, visitas, ultima_visita, que al importar se ignoran salvo
	 * activa). Cabecera en castellano o inglés; sin cabecera, posicional
	 * (origen, destino, tipo, motivo). Separador «,» o «;» (Excel en
	 * castellano), según la primera línea. Con o sin BOM.
	 *
	 * Criterio de «revisar»: una fila con algo en esa columna entra APAGADA y
	 * con lo que diga en la nota. Una regla que alguien marcó para revisar no
	 * se pone a funcionar sola.
	 *
	 * @return array{rows: array<int, array{line: int, source: string, target: string, type: string, note: string, active: bool}>, errors: array<int, string>}
	 */
	public static function parse_csv( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$first = strtok( (string) $csv, "\n" );
		$sep   = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';

		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, (string) $csv );
		rewind( $fh );

		$aliases = array(
			'source' => array( 'origen', 'source', 'from', 'origin', 'url', 'desde' ),
			'target' => array( 'destino', 'target', 'to', 'destination', 'hasta' ),
			'type'   => array( 'tipo', 'type', 'code', 'status', 'codigo', 'código' ),
			'note'   => array( 'motivo', 'nota', 'note', 'notes', 'reason', 'comment' ),
			'review' => array( 'revisar', 'review' ),
			'active' => array( 'activa', 'active', 'enabled' ),
		);
		$cols   = array( 'source' => 0, 'target' => 1, 'type' => 2, 'note' => 3 );
		$rows   = array();
		$errors = array();
		$line   = 0;
		while ( false !== ( $cells = fgetcsv( $fh, 0, $sep, '"', '' ) ) ) {
			++$line;
			if ( array( null ) === $cells || '' === trim( implode( '', array_map( 'strval', $cells ) ) ) ) {
				continue; // Línea vacía.
			}
			$cells = array_map( static fn( $c ) => trim( (string) $c ), $cells );
			if ( 1 === $line ) {
				$header = array_map( static fn( $c ) => self::lower( $c ), $cells );
				$found  = array();
				foreach ( $aliases as $field => $names ) {
					foreach ( $header as $i => $h ) {
						if ( in_array( $h, $names, true ) ) {
							$found[ $field ] = $i;
							break;
						}
					}
				}
				if ( isset( $found['source'] ) ) {
					$cols = $found;
					continue;
				}
			}
			$get    = static fn( string $f ): string => isset( $cols[ $f ], $cells[ $cols[ $f ] ] ) ? self::uncell( $cells[ $cols[ $f ] ] ) : '';
			$review = $get( 'review' );
			$note   = $get( 'note' );
			$active = ! isset( $cols['active'] ) || ! in_array( self::lower( $get( 'active' ) ), array( '0', 'no', 'false', 'off' ), true );
			if ( '' !== $review ) {
				$active = false;
				$note   = trim( $note . ' [revisar: ' . $review . ']' );
			}
			$rows[] = array(
				'line'   => $line,
				'source' => $get( 'source' ),
				'target' => $get( 'target' ),
				'type'   => $get( 'type' ),
				'note'   => $note,
				'active' => $active,
			);
		}
		fclose( $fh );
		return array( 'rows' => $rows, 'errors' => $errors );
	}

	/**
	 * Una celda que el export protegió contra fórmulas («'=…») vuelve a ser lo que era.
	 */
	private static function uncell( string $cell ): string {
		return preg_match( "/^'[=+\-@]/", $cell ) ? substr( $cell, 1 ) : $cell;
	}

	/** Protege una celda del CSV exportado: Excel ejecuta lo que empieza por = + - @. */
	public static function cell( string $value ): string {
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Limpia y valida una regla contra las que ya hay. Pura: las demás llegan
	 * como array clave → regla.
	 *
	 * Lo que rechaza: origen vacío o la portada sola; tipo que no sea
	 * 301/302/410; 301/302 sin destino o con un destino que no sea ruta
	 * («/x») ni URL http(s); destino igual al origen; un origen que ya tiene
	 * otra regla (duplicado); y un bucle: seguir el destino por las reglas
	 * (activas o no) vuelve al origen (A→B→A, A→B→C→A). Una cadena sin bucle
	 * (A→B, B→C) se deja: funciona, con un salto de más.
	 *
	 * Un 410 no lleva destino: si trae uno, se vacía (no es un error; el CSV
	 * de Lavandería no lo trae, pero uno escrito a mano podría).
	 *
	 * @param array{source: string, target: string, type: mixed, note?: string, active?: bool} $rule
	 * @param array<string, array{id?: int, key?: string, target: string, type: int, active: bool}> $others Por clave, sin la propia.
	 * @param string $home Dominio de la web («https://lavanderiamallorca.com»): un destino absoluto a la propia web cuenta como ruta para bucles.
	 * @return array{0: array{source: string, key: string, target: string, type: int, note: string, active: bool}|null, 1: string} [ regla limpia o null, código de error ]
	 */
	public static function validate( array $rule, array $others, string $home = '', string $base = '' ): array {
		$source = trim( (string) $rule['source'] );
		$target = trim( (string) ( $rule['target'] ?? '' ) );
		$type   = (int) ( '' === trim( (string) ( $rule['type'] ?? '' ) ) ? 301 : $rule['type'] );
		$key    = self::key( $source, $base );

		if ( '' === $key ) {
			return array( null, 'empty_source' );
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $source ) && ! self::same_site( $source, $home ) ) {
			return array( null, 'foreign_source' );
		}
		if ( '/' === $key ) {
			return array( null, 'home_source' );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return array( null, 'bad_type' );
		}
		if ( 410 === $type ) {
			$target = '';
		} elseif ( '' === $target ) {
			return array( null, 'empty_target' );
		} elseif ( ! preg_match( '#^/#', $target ) && ! preg_match( '#^https?://[^/\s]+#i', $target ) ) {
			return array( null, 'bad_target' );
		} elseif ( preg_match( '/\s/', $target ) ) {
			return array( null, 'bad_target' );
		}
		if ( isset( $others[ $key ] ) ) {
			return array( null, 'duplicate' );
		}

		if ( '' !== $target ) {
			$next = self::internal_key( $target, $home, $base );
			if ( null !== $next && $next === $key ) {
				return array( null, 'same_target' );
			}
			$seen = array( $key => true );
			for ( $hop = 0; null !== $next && $hop < self::MAX_HOPS; $hop++ ) {
				if ( isset( $seen[ $next ] ) ) {
					return array( null, 'loop' );
				}
				$seen[ $next ] = true;
				$other         = $others[ $next ] ?? null;
				// También las APAGADAS: si no, encender una en lote (que no pasa por aquí) cerraría el bucle.
				if ( ! $other || 410 === (int) $other['type'] ) {
					break;
				}
				$next = self::internal_key( (string) $other['target'], $home, $base );
			}
		}

		return array(
			array(
				'source' => $source,
				'key'    => $key,
				'target' => $target,
				'type'   => $type,
				'note'   => trim( (string) ( $rule['note'] ?? '' ) ),
				'active' => (bool) ( $rule['active'] ?? true ),
			),
			'',
		);
	}

	/** Clave de un destino si es de esta web (ruta o URL con el mismo dominio); null si es de fuera. */
	public static function internal_key( string $target, string $home, string $base = '' ): ?string {
		if ( '' === $target ) {
			return null;
		}
		if ( '/' === $target[0] && 0 !== strpos( $target, '//' ) ) {
			return self::key( $target, $base );
		}
		return self::same_site( $target, $home ) ? self::key( $target, $base ) : null;
	}

	private static function same_site( string $url, string $home ): bool {
		$host = static fn( string $u ): string => preg_replace( '/^www\./', '', self::lower( (string) parse_url( $u, PHP_URL_HOST ) ) );
		return '' !== $home && '' !== $host( $url ) && $host( $url ) === $host( $home );
	}

	private static function join( string $path, array $params ): string {
		if ( ! $params ) {
			return $path;
		}
		$pairs = array();
		foreach ( $params as $k => $v ) {
			$pairs[] = $k . '=' . $v;
		}
		return $path . '?' . implode( '&', $pairs );
	}

	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
