<?php
/**
 * estrategia-web/cookie-table — la tabla de cookies de la política.
 *
 * El HTML lo pinta Cookie_Table::render() (includes/class-cookie-table.php),
 * que comparte con el shortcode [estrategia_web_cookie_table]. Su cabecera es
 * la documentación.
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo \EstrategiaWeb\Site\Cookie_Table::render( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping in render().
