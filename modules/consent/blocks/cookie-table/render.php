<?php
/**
 * estrategia-web/cookie-table — the cookie table of the cookie policy.
 *
 * The HTML is printed by Cookie_Table::render() (includes/class-cookie-table.php),
 * shared with the [estrategia_web_cookie_table] shortcode. Its header is the
 * documentation.
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo \EstrategiaWeb\Site\Cookie_Table::render( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping in render().
