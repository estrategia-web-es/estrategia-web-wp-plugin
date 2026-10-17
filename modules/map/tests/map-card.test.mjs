// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested is what the visitor sees before the click (a card with details, no image) and that no request goes to Google, and that only exists with the page painted.
/**
 * estrategia-web/map 0.3.0, without an image (the normal case): instead of a
 * map photo, a card with the place's DETAILS in real text (name, address,
 * phone with a tel: link, opening hours with their line breaks), the "Ver
 * mapa" (show map) button and the "Abrir en Google Maps" (open in Google
 * Maps) link. No <img> and no request to Google until the click. Seeded page:
 * /prueba-mapa/ (modules/map/tests/wp/estrategia-web-map-test.php).
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

const PAGE = `${ BASE_URL }/prueba-mapa/`;

blockSite( 'map' );

const isGoogle = ( url ) => /google\.|gstatic\.|googleapis\./.test( new URL( url ).hostname );

test( 'map-card', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage();
		const seen = [];
		page.on( 'request', ( request ) => isGoogle( request.url() ) && seen.push( request.url() ) );
		await page.goto( PAGE, { waitUntil: 'networkidle' } );
		const map = page.locator( '.estrategia-web-map' );
		await map.scrollIntoViewIfNeeded();

		assert.equal( await map.locator( 'img' ).count(), 0, 'with no image chosen there should be no <img>' );
		const card = map.locator( '.estrategia-web-map__card' );
		assert.ok( await card.isVisible(), 'the details card is missing' );
		const text = await card.innerText();
		for ( const expected of [ 'Ayuntamiento de prueba', 'Plaça de Cort 1, 07001 Palma', '+34 971 22 59 00', 'De lunes a viernes\nDe 9:00 a 14:00' ] ) {
			assert.ok( text.includes( expected ), `the card does not say "${ expected }":\n${ text }` );
		}
		assert.equal( await card.locator( 'a[href="tel:+34971225900"]' ).count(), 1, 'the phone does not link to tel:' );
		assert.ok( await map.getByRole( 'button', { name: 'Ver mapa' } ).isVisible(), '"Ver mapa" is missing' );
		assert.ok( await map.getByRole( 'link', { name: /Abrir en Google Maps/ } ).isVisible(), '"Abrir en Google Maps" is missing' );
		assert.deepEqual( seen, [], `requests to Google before the click:\n${ seen.join( '\n' ) }` );
		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
	} )
);
