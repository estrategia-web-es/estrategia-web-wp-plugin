<?php
/**
 * Module Name: No comments
 * Description: Turns comments off everywhere: closed on every post, no Comments menu, dashboard widget or toolbar item, no comment blocks on the site and no comment feeds.
 * Default: off
 * Requires:
 *
 * Para las webs de negocio sin blog (Lavandería: los comentarios ya se
 * cerraron a mano con WP-CLI). Todo es en caliente, con filtros: NO escribe
 * nada en la base de datos. Apagar el módulo devuelve cada entrada a su
 * comment_status de siempre, y los comentarios que hubiera siguen ahí.
 *
 * Por qué nace apagado: cambia algo visible (desaparece un menú, se cierran
 * los comentarios de un blog que los tuviera abiertos). Con «on» se
 * encendería solo en todas las webs desplegadas que ya tienen la lista de
 * módulos guardada (caso frontera de includes/class-modules.php), y una web
 * con blog perdería sus comentarios sin que nadie lo hubiera decidido.
 *
 * Qué quita, y con qué:
 * - Abiertos/cerrados: comments_open y pings_open a false, y los valores
 *   por defecto de las entradas nuevas (default_comment_status y
 *   default_ping_status) a «closed» sin tocar la opción guardada.
 * - Los comentarios existentes no se pintan (comments_array vacío, y 0 en
 *   get_comments_number).
 * - El soporte «comments» y «trackbacks» de TODOS los tipos (quita la
 *   columna y la caja del editor), en init tarde para pillar los tipos que
 *   registran el tema y los plugins.
 * - Escritorio: el menú Comentarios (y entrar a edit-comments.php lleva al
 *   Escritorio), los comentarios del widget Actividad y las cifras de
 *   «De un vistazo»; el globo de la barra de administración.
 * - Front: los bloques de comentarios (core/comments con todo lo que lleva
 *   dentro, el formulario, el contador y el enlace, Últimos comentarios) no
 *   se pintan aunque estén en una plantilla del tema.
 * - Feeds: sin enlace al feed de comentarios en el <head>, y pedirlo da 404.
 * - XML-RPC: fuera pingback.ping y la cabecera X-Pingback.
 *
 * Cómo se mantiene:
 * - Quién: quien toque el plugin. Cuándo: si WordPress añade un bloque de
 *   comentarios nuevo (los de hoy: los que empiezan por core/comment y
 *   core/post-comments, más core/latest-comments), entra en BLOCKS.
 * - Criterio: entra lo que deja ver o escribir un comentario. La pantalla
 *   «Ajustes → Comentarios» se queda: es de WordPress y no hace daño, y con
 *   el módulo encendido sus valores simplemente no mandan.
 * - Caso frontera, con nombre: la REST /wp/v2/comments sigue registrada
 *   (el editor la usa para las notas de bloque de WordPress 6.9, que son
 *   comentarios de tipo «note»), pero no se puede comentar porque
 *   comments_open es false. Por eso aquí NO se borran las rutas REST.
 * - La trampa: remove_post_type_support en init a prioridad 10 no ve los
 *   tipos que se registran después en init; por eso va a 100 y además en
 *   registered_post_type para los que lleguen todavía más tarde.
 * - Prueba: modules/comments-off/tests/comments-off.test.mjs (nivel 2).
 */

namespace EstrategiaWeb\CommentsOff;

defined( 'ABSPATH' ) || exit;

/** Bloques que no se pintan: por prefijo de nombre. */
const BLOCKS = array( 'core/comment', 'core/post-comments', 'core/latest-comments' );

add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open', '__return_false', 20 );
add_filter( 'pre_option_default_comment_status', static fn() => 'closed' );
add_filter( 'pre_option_default_ping_status', static fn() => 'closed' );
add_filter( 'comments_array', static fn() => array(), 20 );
add_filter( 'get_comments_number', static fn() => 0, 20 );

$estrategia_web_no_support = static function ( $post_type ): void {
	if ( post_type_supports( (string) $post_type, 'comments' ) ) {
		remove_post_type_support( (string) $post_type, 'comments' );
	}
	if ( post_type_supports( (string) $post_type, 'trackbacks' ) ) {
		remove_post_type_support( (string) $post_type, 'trackbacks' );
	}
};
add_action(
	'init',
	static function () use ( $estrategia_web_no_support ): void {
		array_map( $estrategia_web_no_support, get_post_types() );
	},
	100
);
add_action( 'registered_post_type', $estrategia_web_no_support );

// Escritorio.
add_action(
	'admin_menu',
	static function (): void {
		remove_menu_page( 'edit-comments.php' );
	},
	999
);
add_action(
	'admin_init',
	static function (): void {
		global $pagenow;
		if ( 'edit-comments.php' === $pagenow || 'comment.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}
);
add_action(
	'wp_dashboard_setup',
	static function (): void {
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
	}
);
// El widget Actividad pinta los comentarios recientes solo si la consulta trae alguno.
add_filter( 'dashboard_recent_comments_query_args', static fn( $args ) => array_merge( (array) $args, array( 'comment__in' => array( 0 ) ) ) );
add_action(
	'admin_head-index.php',
	static function (): void {
		echo '<style>#dashboard_right_now .comment-count,#dashboard_right_now .comment-mod-count{display:none}</style>';
	}
);
add_action(
	'admin_bar_menu',
	static function ( $bar ): void {
		$bar->remove_node( 'comments' );
	},
	999
);

// Front: bloques y feeds.
add_filter(
	'render_block',
	static function ( $content, $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		foreach ( BLOCKS as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return '';
			}
		}
		return $content;
	},
	10,
	2
);
add_filter( 'feed_links_show_comments_feed', '__return_false' );
add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' );
add_action(
	'template_redirect',
	static function (): void {
		if ( is_comment_feed() ) {
			wp_die( esc_html__( 'Comments are closed.', 'estrategia-web' ), '', array( 'response' => 404 ) );
		}
	},
	1
);

// XML-RPC.
add_filter(
	'xmlrpc_methods',
	static function ( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}
);
add_filter(
	'wp_headers',
	static function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);
