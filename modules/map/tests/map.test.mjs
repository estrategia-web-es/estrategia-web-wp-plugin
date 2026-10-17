// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested are the network REQUESTS the page makes, and they only exist when it runs.
/**
 * estrategia-web/map WITHOUT the cookie notice (the consent module is off in
 * these tests: tests/browser.mjs, blockSite): no
 * request to Google before the click; after "Ver mapa" (show map), yes. There
 * is no notice nor window.estrategiaWebConsent, and the map does not miss it
 * (no errors).
 * "Accepting maps in the notice loads it without a click" is tested where the
 * notice is: modules/consent (contract) and the site
 * (Lavandería's tests/consent-maps.test.mjs, parent + plugin together).
 *
 * REQUESTS to *.google.com are checked, not occurrences of the text
 * "google.com" in the HTML: the "Abrir en Google Maps" link is there by design.
 *
 * Seen failing on 05-10-2026: with blocks/map/view.js calling
 * window.estrategiaWebConsent.grant() without "?." it blows up on click
 * (pageerror) and the iframe does not load.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

const PAGE = `${ BASE_URL }/prueba-mapa/`;

blockSite( 'map' );

const isGoogle = ( url ) => /^https?:\/\/([a-z0-9-]+\.)*google\.com\//.test( url );

function recordGoogle( page ) {
	const seen = [];
	page.on( 'request', ( request ) => {
		if ( isGoogle( request.url() ) ) {
			seen.push( request.url() );
		}
	} );
	return seen;
}

test( 'map', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage();
		const google = recordGoogle( page );
		await page.goto( PAGE, { waitUntil: 'networkidle' } );

		// Without the plugin: neither notice nor API.
		assert.equal( await page.locator( '#estrategia-web-consent' ).count(), 0, 'the parent no longer paints the cookie notice' );
		assert.equal( await page.evaluate( () => typeof window.estrategiaWebConsent ), 'undefined', 'without the plugin there should be no window.estrategiaWebConsent' );

		// Two clicks: nothing before, the load after.
		await page.locator( '.estrategia-web-map' ).scrollIntoViewIfNeeded();
		await page.waitForLoadState( 'networkidle' );
		assert.deepEqual( google, [], `requests to Google before the click:\n${ google.join( '\n' ) }` );
		// No src or src="" (about:blank): hydration leaves "" — see blocks/map/view.js.
		const srcBefore = await page.locator( '.estrategia-web-map__iframe' ).getAttribute( 'src' );
		assert.ok( ! srcBefore, `the iframe already has a src before the click: ${ srcBefore }` );

		const embedRequest = page.waitForRequest( ( request ) => /google\.com\/maps\/embed/.test( request.url() ) );
		await page.getByRole( 'button', { name: 'Ver mapa' } ).click();
		await embedRequest;
		assert.ok( await page.locator( '.estrategia-web-map__iframe' ).isVisible(), 'the iframe is not visible after the click' );
		assert.ok( await page.getByRole( 'button', { name: 'Ver mapa' } ).isHidden(), 'the button is still visible' );
		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
	} )
);
