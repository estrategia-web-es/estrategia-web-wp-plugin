// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: it checks the accessible name and that the button is fixed and visible on screen, which is computed style.
/**
 * estrategia-web/whatsapp: the wa.me link is correct with attributes (number
 * with spaces and "+", message with accents) and with the site option (a
 * block without attributes: in the theme repo it was the footer's, here the
 * page carries it), it opens in a new tab, has an accessible name and stays
 * fixed in its corner.
 * Since 0.7.1, the distance to the corner adds the mobile safe area
 * (env(safe-area-inset-*)): it is checked in the stylesheet, because in a
 * desktop browser env() is 0 and the computed style does not tell it apart.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

const PAGE = `${ BASE_URL }/prueba-whatsapp/`;

blockSite( 'whatsapp' );

test( 'whatsapp', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage( { viewport: { width: 1200, height: 900 } } );
		await page.goto( PAGE, { waitUntil: 'networkidle' } );

		const buttons = page.getByRole( 'link', { name: /Escríbenos por WhatsApp/ } );
		assert.equal( await buttons.count(), 2, 'two were expected: the one with attributes and the one without' );

		const hrefs = await buttons.evaluateAll( ( links ) => links.map( ( a ) => a.getAttribute( 'href' ) ) );
		assert.ok(
			hrefs.includes( 'https://wa.me/34600111222?text=' + encodeURIComponent( 'Hola, ¿tenéis hora mañana?' ) ),
			`the link with attributes is missing: ${ hrefs.join( ' ' ) }`
		);
		assert.ok(
			hrefs.includes( 'https://wa.me/34611223344?text=' + encodeURIComponent( 'Mensaje desde la opción del sitio' ) ),
			`the site option's link is missing: ${ hrefs.join( ' ' ) }`
		);

		for ( const link of await buttons.all() ) {
			assert.equal( await link.getAttribute( 'target' ), '_blank' );
			assert.match( await link.getAttribute( 'rel' ), /noopener/ );
			assert.equal( await link.evaluate( ( el ) => getComputedStyle( el ).position ), 'fixed' );
			const box = await link.boundingBox();
			assert.ok( box && box.width >= 44 && box.height >= 44, 'touch target smaller than 44 px' );
		}

		// Position: the one with attributes goes on the left, the one without on the right.
		const left = await page.locator( '.estrategia-web-whatsapp.is-position-bottom-left' ).boundingBox();
		const right = await page.locator( '.estrategia-web-whatsapp.is-position-bottom-right' ).boundingBox();
		assert.ok( left.x < 100 && right.x > 1000, `unexpected positions: ${ left.x } / ${ right.x }` );

		// Safe area (0.7.1): at the bottom and on its corner's side, each its own.
		const insets = await page.evaluate( () => {
			const found = {};
			const walk = ( rules ) => {
				for ( const rule of rules ) {
					if ( rule.cssRules ) {
						walk( rule.cssRules );
					}
					if ( ! rule.selectorText || ! rule.selectorText.includes( '.estrategia-web-whatsapp' ) ) {
						continue;
					}
					for ( const prop of [ 'inset-block-end', 'inset-inline-start', 'inset-inline-end' ] ) {
						const value = rule.style.getPropertyValue( prop );
						if ( value ) {
							found[ `${ rule.selectorText } ${ prop }` ] = value;
						}
					}
				}
			};
			for ( const sheet of document.styleSheets ) {
				try {
					walk( sheet.cssRules );
				} catch {}
			}
			return found;
		} );
		const insetOf = ( selector, prop ) => insets[ `${ selector } ${ prop }` ] || '';
		assert.match( insetOf( '.estrategia-web-whatsapp', 'inset-block-end' ), /safe-area-inset-bottom/, JSON.stringify( insets ) );
		assert.match( insetOf( '.estrategia-web-whatsapp.is-position-bottom-left', 'inset-inline-start' ), /safe-area-inset-left/, JSON.stringify( insets ) );
		assert.match( insetOf( '.estrategia-web-whatsapp.is-position-bottom-right', 'inset-inline-end' ), /safe-area-inset-right/, JSON.stringify( insets ) );

		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
	} )
);
