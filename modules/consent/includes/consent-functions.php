<?php
/**
 * Global functions of the cookie notice: the PHP contract the parent theme
 * had (≤ 0.6.0, inc/consent.php), with the same names, so that a theme or a
 * plugin that calls them keeps working. Inside, Consent.
 *
 * NOT loaded with the plugin: Consent::take_over() loads it on
 * after_setup_theme, and only if the theme has not defined them already (an
 * old parent that still ships the notice). Loading it earlier would make that
 * parent crash when redeclaring them. See the header of
 * includes/class-consent.php.
 *
 * How to maintain: a new public function of Consent that makes sense to call
 * from a theme gets its estrategia_web_consent_* wrapper here. Removing one
 * is a MAJOR version.
 *
 * @package estrategia-web-site
 */

use EstrategiaWeb\Site\Consent;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_CONSENT_COOKIE' ) ) {
	define( 'ESTRATEGIA_WEB_CONSENT_COOKIE', Consent::COOKIE );
}

/**
 * Optional categories that can be described.
 *
 * @return array<string, array{label: string, description: string}>
 */
function estrategia_web_consent_known_categories(): array {
	return Consent::known_categories();
}

/**
 * Optional categories declared by the site.
 *
 * @return string[]
 */
function estrategia_web_consent_categories(): array {
	return Consent::categories();
}

/** Revision of the declared categories. */
function estrategia_web_consent_revision(): string {
	return Consent::revision();
}

/** Lifetime of the choice, in seconds. */
function estrategia_web_consent_max_age(): int {
	return Consent::max_age();
}

/**
 * The stored choice, or null.
 *
 * @return string[]|null
 */
function estrategia_web_consent_choice(): ?array {
	return Consent::choice();
}

/**
 * Whether a category is accepted (reads the cookie on the server: beware of
 * page caching).
 *
 * @param string $category Category slug.
 */
function estrategia_web_consent_has( string $category ): bool {
	return Consent::has( $category );
}
