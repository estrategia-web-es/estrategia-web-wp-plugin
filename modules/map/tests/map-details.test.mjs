// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested is which text is IN VIEW (innerText, leaving out what is hidden) and which box is painted before and after "Ver mapa", which only exists with the page hydrated.
/**
 * estrategia-web/map without the details card (0.6.0, showDetails:false
 * attribute), for when the location's card already gives the address next to
 * the map:
 * 1. the address is read ONCE on the page (the card's), not twice;
 * 2. with a static map, the frame shows only the image, and below it "Ver
 *    mapa" (show map) and "Abrir en Google Maps" (open in Google Maps);
 * 3. without an image, before the click there is no empty box: only the
 *    buttons; and "Ver mapa" paints the map with its height.
 *
 * Page seeded in modules/map/tests/wp/estrategia-web-map-test.php (/prueba-mapa-sin-datos/).
 * Google is cut off with page.route: the test does not go out to the Internet.
 * Seen failing on 03-10-2026 with 0.5.0: the address came out twice (the
 * attribute did not exist and the card was always painted).
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'map' );

test( 'map-details', () =>
	withBrowser( async ( { newPage } ) => {
		for ( const width of [ 1200, 402 ] ) {
			const page = await newPage( { viewport: { width, height: 900 } } );
			await page.route( /google\.com/, ( route ) => route.fulfill( { status: 200, contentType: 'text/html', body: '<p>mapa</p>' } ) );
			await page.goto( `${ BASE_URL }/prueba-mapa-sin-datos/`, { waitUntil: 'networkidle' } );

			// 1. The address, only once in view.
			const text = await page.locator( 'body' ).innerText();
			const times = text.split( 'Plaça de Cort 1' ).length - 1;
			assert.equal( times, 1, `at ${ width } px the address comes out ${ times } times` );
			assert.ok( ! text.includes( 'Sede sin recuadro' ), `at ${ width } px the location name comes out inside the map` );

			// 2. With a static map: only the image, and the two buttons.
			const first = page.locator( '.estrategia-web-map' ).nth( 0 );
			assert.equal( await first.locator( '.estrategia-web-map__card' ).count(), 0, 'with showDetails:false the card is not painted' );
			const image = first.locator( 'img.estrategia-web-map__static' );
			await image.scrollIntoViewIfNeeded();
			await page.waitForFunction( ( el ) => el.complete && el.naturalWidth > 0, await image.elementHandle() );
			const frame = await first.locator( '.estrategia-web-map__frame' ).boundingBox();
			const img = await image.boundingBox();
			assert.ok( Math.abs( img.height - frame.height ) < 2 && img.height > 100, `at ${ width } px the image does not fill the frame (${ img.height } of ${ frame.height })` );
			assert.ok( await first.getByRole( 'button', { name: 'Ver mapa' } ).isVisible() );
			assert.ok( await first.getByRole( 'link', { name: /Abrir en Google Maps/ } ).isVisible() );

			// 3. Without an image: no empty box before the click; the map, after.
			const second = page.locator( '.estrategia-web-map' ).nth( 1 );
			assert.equal( await second.locator( '.estrategia-web-map__card' ).count(), 0 );
			assert.ok( await second.locator( '.estrategia-web-map__frame' ).isHidden(), `at ${ width } px without an image an empty box is left before the click` );
			assert.ok( await second.getByRole( 'link', { name: /Abrir en Google Maps/ } ).isVisible() );
			// Plugin 1.1.x: two links to different destinations cannot have the
			// same name (Lighthouse, accessibility). The name starts with the visible text.
			const names = await page.locator( '.estrategia-web-map__link' ).evaluateAll( ( links ) => links.map( ( a ) => a.getAttribute( 'aria-label' ) || a.textContent.trim() ) );
			assert.equal( new Set( names ).size, names.length, `repeated link names: ${ names.join( ' | ' ) }` );
			for ( const name of names ) {
				assert.ok( name.startsWith( 'Abrir en Google Maps' ), `the name does not start with the visible text: ${ name }` );
			}
			await second.getByRole( 'button', { name: 'Ver mapa' } ).click();
			const iframe = second.locator( '.estrategia-web-map__iframe' );
			await page.waitForFunction( ( el ) => ! el.hidden && /google\.com/.test( el.src ) && el.getBoundingClientRect().height > 100, await iframe.elementHandle() );
			assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
		}
	} )
);
