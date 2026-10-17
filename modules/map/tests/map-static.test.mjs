// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested are the network REQUESTS (who is asked for what) and where the card ends up painted over the image, and that only exists when the page runs.
/**
 * estrategia-web/map with a static map (0.5.0, staticMapUrl attribute):
 * 1. without accepting maps, the OSM image served by the site ITSELF is seen,
 *    with the details card on top (on the left), and the page requests
 *    NOTHING from another domain;
 * 2. a staticMapUrl from another domain is discarded: that map has no image;
 * 3. "Ver mapa" (show map) loads Google for THAT map. Without the cookie
 *    notice plugin (0.7.0) it grants nothing: the other map stays unloaded and
 *    there is no cookie. With the plugin, "Ver mapa" grants "maps" and the
 *    others load: the site tests that (Lavandería,
 *    tests/consent-maps.test.mjs);
 * 4. in a narrow block, the card goes BELOW the map, not on top.
 *
 * Page seeded in modules/map/tests/wp/estrategia-web-map-test.php
 * (/prueba-mapa-estatico/); the image is drawn there with GD and uploaded to
 * the media library (in the theme repo it was an OSM tile). Google is cut off
 * with page.route: the test does not go out to the Internet, it checks what
 * is REQUESTED.
 * Seen failing on 17-10-2026 when it moved from the theme to the plugin: in
 * Twenty Twenty-Five the card measured 42 % PLUS its padding and reached the
 * marker (point 1); box-sizing: border-box in blocks/map/style.css.
 * Seen failing on 03-10-2026 with 0.4.0: no static image (the attribute did
 * not exist) and "maps" not granted after "Ver mapa".
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'map' );

const URL = `${ BASE_URL }/prueba-mapa-estatico/`;
const own = new globalThis.URL( BASE_URL ).host;

test( 'map-static', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage( { viewport: { width: 1200, height: 900 } } );
		const foreign = [];
		page.on( 'request', ( request ) => {
			const host = new globalThis.URL( request.url() ).host;
			if ( request.url().startsWith( 'http' ) && host !== own ) {
				foreign.push( request.url() );
			}
		} );
		await page.route( /google\.com/, ( route ) => route.fulfill( { status: 200, contentType: 'text/html', body: '<p>mapa</p>' } ) );
		await page.goto( URL, { waitUntil: 'networkidle' } );

		const first = page.locator( '.estrategia-web-map' ).nth( 0 );
		const second = page.locator( '.estrategia-web-map' ).nth( 1 );

		// 1. Own image, loaded, with the card on top on the left.
		const image = first.locator( 'img.estrategia-web-map__static' );
		await image.scrollIntoViewIfNeeded();
		await page.waitForFunction( ( el ) => el.complete && el.naturalWidth > 0, await image.elementHandle() );
		const src = await image.getAttribute( 'src' );
		assert.equal( new globalThis.URL( src ).host, own, `the image is not from the site: ${ src }` );
		assert.match( await image.getAttribute( 'alt' ), /Pla(ç|c)a de Cort/, 'the alternative text does not give the address' );
		const card = first.locator( '.estrategia-web-map__card' );
		assert.ok( await card.isVisible(), 'with a static map the details card must stay' );
		assert.match( await card.innerText(), /Sede con mapa estático/ );
		const imageBox = await image.boundingBox();
		const cardBox = await card.boundingBox();
		assert.ok( cardBox.y < imageBox.y + imageBox.height && cardBox.y >= imageBox.y, 'when wide, the card goes ON TOP of the map' );
		assert.ok( cardBox.x + cardBox.width < imageBox.x + imageBox.width / 2, 'the card covers the centre (the marker)' );

		// 2. The URL from another domain is discarded.
		assert.equal( await second.locator( 'img' ).count(), 0, 'a staticMapUrl from another domain is not discarded' );
		assert.deepEqual( foreign, [], `third-party requests without consent: ${ foreign.join( ' ' ) }` );

		// 3. Without the notice plugin (0.7.0): "Ver mapa" loads THIS map and
		// only this one; there is no consent to grant nor cookie to store.
		await first.getByRole( 'button', { name: 'Ver mapa' } ).click();
		await page.waitForFunction( () => {
			const frame = document.querySelectorAll( '.estrategia-web-map__iframe' )[ 0 ];
			return frame && ! frame.hidden && /google\.com/.test( frame.src );
		} );
		assert.ok( await image.isHidden(), 'once the map is loaded, the static image is still in view' );
		assert.ok( ! ( await second.locator( '.estrategia-web-map__iframe' ).getAttribute( 'src' ) ), 'without the notice, clicking one map should not load the others' );
		assert.equal( ( await page.context().cookies() ).find( ( c ) => c.name === 'estrategia_web_consent' ), undefined, 'without the plugin there is no consent cookie' );
		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );

		// 4. Narrow: the card below the map.
		const narrow = await newPage( { viewport: { width: 375, height: 800 } } );
		await narrow.goto( URL, { waitUntil: 'networkidle' } );
		const narrowImage = narrow.locator( '.estrategia-web-map' ).nth( 0 ).locator( 'img.estrategia-web-map__static' );
		await narrowImage.scrollIntoViewIfNeeded();
		await narrow.waitForFunction( ( el ) => el.complete && el.naturalWidth > 0, await narrowImage.elementHandle() );
		const a = await narrowImage.boundingBox();
		const b = await narrow.locator( '.estrategia-web-map' ).nth( 0 ).locator( '.estrategia-web-map__card' ).boundingBox();
		assert.ok( a.height > 100, `when narrow the map is ${ a.height } px high` );
		assert.ok( b.y >= a.y + a.height - 1, `when narrow the card (y=${ b.y }) does not go below the map (which ends at ${ a.y + a.height })` );
	} )
);
