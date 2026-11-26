<?php
/**
 * RECOMMENDED: the company details are incomplete — legal name, tax ID
 * (NIF/CIF), address or phone empty in Estrategia Web › Company details.
 *
 * Criterion: those four are what the legal notice must show (LSSI art. 10:
 * name, tax ID, address and a way to contact) and what the structured data
 * tells Google (local-business: Organization name, telephone, address). On
 * our sites the legal notice and the footer READ them from here (the
 * [estrategia_web_company] shortcode, the company binding source), so an
 * empty field is an empty line on the real site. The keys are those of
 * modules/site/includes/class-settings.php (legal_name, tax_id, address,
 * phone), read through Site\Settings::all() so the site's filter applies.
 *
 * Edge case, by name: a site whose legal notice is typed by hand, not bound
 * to these fields, still gets the warning — on purpose: the schema and any
 * future bound block read the fields, not the page.
 *
 * Real case: one of the findings of the Llar d'Estels audit (25-26/11/2026).
 *
 * The trap: company_name is NOT required here: the site's title stands in
 * for it everywhere; the legal NAME (with «S.L.») is the one the law asks.
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'     => 'company-details',
	'async'  => false,
	'title'  => static fn() => __( 'Company details are complete', 'estrategiaweb-toolkit' ),
	'gather' => static function (): array {
		$data = class_exists( \EstrategiaWeb\Site\Settings::class ) ? \EstrategiaWeb\Site\Settings::all() : (array) get_option( 'estrategia_web_site', array() );
		$out  = array();
		foreach ( array( 'legal_name', 'tax_id', 'address', 'phone' ) as $key ) {
			$out[ $key ] = trim( (string) ( $data[ $key ] ?? '' ) );
		}
		return $out;
	},
	'judge'  => static function ( array $d ): array {
		$labels  = array(
			'legal_name' => __( 'Legal name', 'estrategiaweb-toolkit' ),
			'tax_id'     => __( 'Tax ID', 'estrategiaweb-toolkit' ),
			'address'    => __( 'Address', 'estrategiaweb-toolkit' ),
			'phone'      => __( 'Phone', 'estrategiaweb-toolkit' ),
		);
		$missing = array();
		foreach ( $labels as $key => $label ) {
			if ( '' === trim( (string) ( $d[ $key ] ?? '' ) ) ) {
				$missing[] = $label;
			}
		}
		if ( ! $missing ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Company details are complete', 'estrategiaweb-toolkit' ),
				'description' => __( 'The legal name, tax ID, address and phone are filled in.', 'estrategiaweb-toolkit' ),
			);
		}
		return array(
			'status'      => 'recommended',
			'label'       => __( 'Company details are incomplete', 'estrategiaweb-toolkit' ),
			'description' => __( 'The legal notice must show the legal name, tax ID, address and a phone, and the site tells Google about the company from these same fields. Empty fields:', 'estrategiaweb-toolkit' ),
			'items'       => $missing,
			'actions'     => array(
				array(
					'path' => 'admin.php?page=estrategia-web-site',
					'text' => __( 'Estrategia Web › Company details', 'estrategiaweb-toolkit' ),
				),
			),
		);
	},
);
