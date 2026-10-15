<?php
/**
 * estrategia-web-site/consent — button to reopen the cookie notice (for the footer).
 *
 * The HTML is printed by Consent::render_link() (includes/class-consent.php),
 * shared with the estrategia-web/consent alias and with the
 * [estrategia_web_consent_settings] shortcode: a single place for the markup.
 *
 * @package estrategia-web-site
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo \EstrategiaWeb\Site\Consent::render_link( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping in render_link().
