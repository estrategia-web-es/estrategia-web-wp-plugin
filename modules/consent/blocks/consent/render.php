<?php
/**
 * estrategia-web-site/consent — botón para reabrir el aviso de cookies (para el pie).
 *
 * El HTML lo pinta Consent::render_link() (includes/class-consent.php), que
 * comparte con el alias estrategia-web/consent y con el shortcode
 * [estrategia_web_consent_settings]: un solo sitio para el marcado.
 *
 * @package estrategia-web-site
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo \EstrategiaWeb\Site\Consent::render_link( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping in render_link().
