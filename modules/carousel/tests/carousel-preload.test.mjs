// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: whether the preload is used (one download, at high priority) or duplicates the <img>, and whether its media keeps it off mobile, is decided by the browser's preload scanner; the HTML alone does not say it.
/**
 * Desktop preload of the slides visible on load (Estrategia Web plugin 1.4.2,
 * preloadSlides attribute / estrategia_web_carousel_preload filter;
 * modules/carousel/includes/carousel-images.php, preload_config()).
 * On /prueba-carrusel-movil/ (crop on mobile, preloadSlides 2):
 * - the <head> carries ONE <link rel="preload" as="image" fetchpriority="high"
 *   media="(min-width: 782px)">, for slide 2, with exactly the srcset and
 *   sizes of its <img> (not the mobile crop's <source>);
 * - at 1512 slide 2's photo is downloaded ONCE and requested at High
 *   priority (without the preload it goes at Low/Medium: it is an <img>
 *   without fetchpriority);
 * - at 402 none of the preload's files is downloaded (only the crops);
 * - on /prueba-carrusel-fotos/ (no preloadSlides) there is no preload: the
 *   default changes nothing for the sites that do not ask.
 * Seen red (16-10-2026) with preload_config() returning null: «expected one
 * image preload, there are 0».
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'carousel' );

const URL = `${ BASE_URL }/prueba-carrusel-movil/`;

const candidates = ( srcset ) => ( srcset || '' ).split( ',' ).map( ( c ) => c.trim().split( /\s+/ )[ 0 ] ).filter( Boolean );

/** Loads the page recording every image request with its initial priority (CDP). */
async function load( newPage, viewport, url = URL ) {
	const page = await newPage( { viewport, deviceScaleFactor: viewport.width < 782 ? 2 : 1 } );
	const cdp = await page.context().newCDPSession( page );
	await cdp.send( 'Network.enable' );
	const requests = [];
	cdp.on( 'Network.requestWillBeSent', ( e ) => {
		if ( 'Image' === e.type || /\.(webp|jpe?g|png)(\?|$)/.test( e.request.url ) ) {
			requests.push( { url: e.request.url, priority: e.request.initialPriority } );
		}
	} );
	await page.goto( url, { waitUntil: 'load' } );
	await page.waitForTimeout( 300 );
	return { page, requests };
}

test( 'carousel-preload', () =>
	withBrowser( async ( { newPage } ) => {
		const desk = await load( newPage, { width: 1512, height: 900 } );
		const head = await desk.page.evaluate( () => {
			const links = [ ...document.head.querySelectorAll( 'link[rel="preload"][as="image"]' ) ].map( ( l ) => ( {
				media: l.getAttribute( 'media' ),
				priority: l.getAttribute( 'fetchpriority' ),
				srcset: l.getAttribute( 'imagesrcset' ),
				sizes: l.getAttribute( 'imagesizes' ),
			} ) );
			const imgs = [ ...document.querySelectorAll( '.estrategia-web-carousel__track img' ) ].map( ( img ) => ( {
				srcset: img.getAttribute( 'srcset' ),
				sizes: img.getAttribute( 'sizes' ),
				src: img.currentSrc,
			} ) );
			return { links, imgs };
		} );
		assert.equal( head.links.length, 1, `expected one image preload, there are ${ head.links.length }` );
		const [ link ] = head.links;
		assert.equal( link.media, '(min-width: 782px)' );
		assert.equal( link.priority, 'high' );
		assert.equal( link.srcset, head.imgs[ 1 ].srcset, 'the preload is not slide 2\'s srcset' );
		assert.equal( link.sizes, head.imgs[ 1 ].sizes, 'the preload does not have slide 2\'s sizes' );
		assert.notEqual( link.srcset, head.imgs[ 0 ].srcset, 'slide 1 must not be preloaded (its <img> already has fetchpriority)' );

		const second = head.imgs[ 1 ].src;
		const hits = desk.requests.filter( ( r ) => r.url === second );
		assert.equal( hits.length, 1, `at 1512 slide 2 (${ second }) is requested ${ hits.length } times` );
		assert.equal( hits[ 0 ].priority, 'High', `at 1512 slide 2 is requested at ${ hits[ 0 ].priority }, not High` );
		assert.deepEqual( desk.page.errors, [], desk.page.errors.join( '\n' ) );

		const phone = await load( newPage, { width: 402, height: 874 } );
		const desktopFiles = new Set( candidates( link.srcset ).map( ( u ) => new globalThis.URL( u, BASE_URL ).href ) );
		const extra = phone.requests.filter( ( r ) => desktopFiles.has( r.url ) );
		assert.deepEqual( extra.map( ( r ) => r.url ), [], 'at 402 the desktop preload downloads files' );
		assert.deepEqual( phone.page.errors, [], phone.page.errors.join( '\n' ) );

		const other = await load( newPage, { width: 1512, height: 900 }, `${ BASE_URL }/prueba-carrusel-fotos/` );
		const none = await other.page.evaluate( () => document.head.querySelectorAll( 'link[rel="preload"][as="image"]' ).length );
		assert.equal( none, 0, 'a carousel without preloadSlides got a preload' );
	} )
);
