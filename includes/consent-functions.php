<?php
/**
 * Funciones globales del aviso de cookies: el contrato PHP que tenía el tema
 * padre (≤ 0.6.0, inc/consent.php), con los mismos nombres, para que un tema
 * o un plugin que las llame siga funcionando. Por dentro, Consent.
 *
 * NO se carga con el plugin: la carga Consent::take_over() en
 * after_setup_theme, y solo si el tema no las ha definido ya (un padre viejo
 * que aún trae el aviso). Cargarla antes haría que ese padre reventara al
 * redeclararlas. Ver la cabecera de includes/class-consent.php.
 *
 * Cómo se mantiene: una función pública nueva de Consent que tenga sentido
 * llamar desde un tema lleva aquí su envoltorio estrategia_web_consent_*. Quitar
 * una es versión MAYOR.
 *
 * @package estrategia-web-site
 */

use EstrategiaWeb\Site\Consent;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_CONSENT_COOKIE' ) ) {
	define( 'ESTRATEGIA_WEB_CONSENT_COOKIE', Consent::COOKIE );
}

/**
 * Categorías opcionales que se saben describir.
 *
 * @return array<string, array{label: string, description: string}>
 */
function estrategia_web_consent_known_categories(): array {
	return Consent::known_categories();
}

/**
 * Categorías opcionales declaradas por la web.
 *
 * @return string[]
 */
function estrategia_web_consent_categories(): array {
	return Consent::categories();
}

/** Revisión de las categorías declaradas. */
function estrategia_web_consent_revision(): string {
	return Consent::revision();
}

/** Vida de la elección, en segundos. */
function estrategia_web_consent_max_age(): int {
	return Consent::max_age();
}

/**
 * La elección guardada, o null.
 *
 * @return string[]|null
 */
function estrategia_web_consent_choice(): ?array {
	return Consent::choice();
}

/**
 * Si una categoría está aceptada (lee la cookie en el servidor: cuidado con
 * la caché de página).
 *
 * @param string $category Slug de la categoría.
 */
function estrategia_web_consent_has( string $category ): bool {
	return Consent::has( $category );
}
