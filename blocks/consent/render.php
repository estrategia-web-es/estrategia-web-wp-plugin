<?php
/**
 * armazon-site/consent — botón para reabrir el aviso de cookies (para el pie).
 *
 * El HTML lo pinta Consent::render_link() (includes/class-consent.php), que
 * comparte con el alias armazon/consent y con el shortcode
 * [armazon_consent_settings]: un solo sitio para el marcado.
 *
 * @package armazon-site
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo \Armazon\Site\Consent::render_link( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping in render_link().
