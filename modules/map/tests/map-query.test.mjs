// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the bindings are resolved when the page is rendered inside the Query loop, and what has to be checked is each post's final HTML.
/**
 * estrategia-web/map inside a Query (0.2.0): each map shows the address of
 * ITS post (an attribute bound to core/post-meta) and, with no image chosen in
 * the block, uses the post's featured image; the post without one gets the
 * neutral card. Page seeded in modules/map/tests/wp/estrategia-web-map-test.php.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'map' );

test( 'map-query', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage();
		await page.goto( `${ BASE_URL }/prueba-mapa-consulta/`, { waitUntil: 'networkidle' } );

		const maps = page.locator( '.estrategia-web-map' );
		assert.equal( await maps.count(), 2, 'there should be one map per post' );

		// With an image the address goes in the map's caption; without one, in the card (0.3.0).
		const addresses = await page.locator( '.estrategia-web-map__address, .estrategia-web-map__card-address' ).allTextContents();
		assert.deepEqual( addresses, [ 'Calle de Prueba 1, Palma', 'Calle de Prueba 2, Palma' ], 'each map should carry its post\'s address' );

		assert.equal( await maps.nth( 0 ).locator( 'img.estrategia-web-map__image' ).count(), 1, 'the first post has a featured image: the map should use it' );
		assert.ok( ( await maps.nth( 0 ).locator( 'img' ).getAttribute( 'src' ) ).includes( 'mapa-estatico-prueba' ), 'the image is not the featured one' );
		assert.equal( await maps.nth( 1 ).locator( '.estrategia-web-map__card' ).count(), 1, 'without a featured image the details card should come out' );

		// The "Abrir en Google Maps" link comes from the bound address.
		const href = await maps.nth( 1 ).locator( '.estrategia-web-map__link' ).getAttribute( 'href' );
		assert.ok( href.includes( encodeURIComponent( 'Calle de Prueba 2, Palma' ) ), `link without the address: ${ href }` );

		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
	} )
);
