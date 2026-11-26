<?php
/**
 * RECOMMENDED: the company has no social profiles — the social module is
 * off or its list (estrategia_web_social_links) is empty.
 *
 * Criterion: the Organization of the structured data (local-business) takes
 * its sameAs from that list (LocalBusiness\Data::company()), and sameAs is
 * how Google ties the site to the company's profiles (and to its knowledge
 * panel). An empty list is an empty sameAs: nothing tells Google those
 * profiles are the same business. The footer's social-links block reads the
 * same list.
 *
 * Edge case, by name: a business that really has no profile at all gets the
 * warning anyway; it is «recommended», not critical, and it is worth
 * knowing. A profile typed only in the theme's footer (a plain link) is not
 * seen: it is not in sameAs either, which is the point.
 *
 * Real case: one of the findings of the Llar d'Estels audit (25-26/11/2026):
 * the schema's sameAs was empty.
 *
 * The trap: the old fixed fields of Company details (social.facebook…) are
 * migrated into the list the first time the social module loads; a site that
 * never switched it on still has them there, unused by the schema.
 */

defined( 'ABSPATH' ) || exit;

use EstrategiaWeb\SiteHealth\Probe;

return array(
	'id'     => 'social-profiles',
	'async'  => false,
	'title'  => static fn() => __( 'The company\'s social profiles are listed', 'estrategiaweb-toolkit' ),
	'gather' => static fn() => array(
		'module' => Probe::module_on( 'social' ),
		'count'  => class_exists( \EstrategiaWeb\Social\Links::class ) ? count( \EstrategiaWeb\Social\Links::items() ) : 0,
	),
	'judge'  => static function ( array $d ): array {
		if ( ! empty( $d['module'] ) && (int) ( $d['count'] ?? 0 ) > 0 ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'The company\'s social profiles are listed', 'estrategiaweb-toolkit' ),
				'description' => __( 'Search engines are told which social profiles belong to the company.', 'estrategiaweb-toolkit' ),
			);
		}
		return array(
			'status'      => 'recommended',
			'label'       => __( 'The company has no social profiles listed', 'estrategiaweb-toolkit' ),
			'description' => empty( $d['module'] )
				? __( 'The Social networks module is off, so nothing tells search engines which profiles belong to the company (the «sameAs» of its structured data is empty).', 'estrategiaweb-toolkit' )
				: __( 'The list of social networks is empty, so nothing tells search engines which profiles belong to the company (the «sameAs» of its structured data is empty).', 'estrategiaweb-toolkit' ),
			'actions'     => array(
				empty( $d['module'] )
					? array(
						'path' => 'admin.php?page=estrategia-web',
						'text' => __( 'Estrategia Web › Modules', 'estrategiaweb-toolkit' ),
					)
					: array(
						'path' => 'admin.php?page=estrategia-web-social',
						'text' => __( 'Estrategia Web › Social networks', 'estrategiaweb-toolkit' ),
					),
			),
		);
	},
);
