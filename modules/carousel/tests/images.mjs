// NIVEL: BANCO DE TRABAJO — not a test file: the photo checks that carousel.test.mjs runs (at the same time as its other pages, 28-10-2026: it was carousel-images.test.mjs, with its own reset, seed and browser). Level 3 because: which srcset photo or <source> crop is downloaded, at which priority and whether the preload is used or duplicated, is decided by the browser (sizes, the painted width, media, the preload scanner); without painting there is no currentSrc.
/**
 * estrategia-web/carousel with photos. Merged on 28-10-2026 from
 * carousel-images, carousel-mobile-crop and carousel-preload, which loaded
 * the same two pages eight times in three browsers: now FOUR loads (each
 * page at 1512 and at 402), every assertion on them. The geometry of the
 * crop is level 1 (mobile-crop-pure.test.mjs).
 *
 * /prueba-carrusel-fotos/ (0.7.4; 1920×1280 photos, no preloadSlides):
 * - the TOP carousel (1/2/3 in view, four photos): the first one has
 *   fetchpriority="high" (0.7.9: ONLY the first), the other visible ones
 *   load now without priority, none has loading="lazy"; the fourth, which is
 *   not seen, is deferred (loading="lazy", no priority, sizes="auto, …").
 * - the BOTTOM one (3000 px further down): no photo with priority, all
 *   deferred.
 * - at 1512 and 402 wide, the downloaded photo of each visible slide is at
 *   least its painted width and no more than twice: the sizes gives the
 *   slide's width, not the window's.
 * - the internal mark data-estrategia-web-carousel does not reach the page.
 * - no image preload: the default changes nothing for sites that do not ask.
 * Against 0.7.3 it fails: only the first one had priority, the fourth was not
 * deferred and at 1512 it downloaded the 1536 one for a ~500 slot.
 *
 * /prueba-carrusel-movil/ (plugin 1.1.x mobileRatio "4/5", 1.4.2 preloadSlides 2;
 * includes/carousel-mobile-crop.php, includes/carousel-images.php preload_config()):
 * - each <img> is inside a <picture> with a <source media="(max-width: 781px)">;
 * - at 402 px (2x) the downloaded photo is the CROP: 4/5 ratio, as wide as the
 *   box asks for (≥ 804 px), and not the 1536 landscape one; the first one
 *   keeps fetchpriority="high"; none of the desktop preload's files is
 *   downloaded;
 * - at 1512 px the usual landscape one is downloaded; the <head> carries ONE
 *   <link rel="preload" as="image" fetchpriority="high" media="(min-width: 782px)">
 *   for slide 2, with exactly the srcset and sizes of its <img>, and slide
 *   2's photo is downloaded ONCE at High priority;
 * - idempotent: the second visit (the 1512 one) serves the same crop files
 *   (it does not remake them under another name).
 * Seen red (14-10-2026) before carousel-mobile-crop.php was written: there was
 * no <picture> and at 402 it downloaded the 1536 one. Seen red (16-10-2026)
 * with preload_config() returning null: «expected one image preload, there are 0».
 * Seen red (28-10-2026, the merged file) with preload_config() never
 * preloading (the 1512 movil load: «expected one image preload, there are 0»).
 */
import { assert, BASE_URL } from '../../../tests/browser.mjs';

const FOTOS = `${ BASE_URL }/prueba-carrusel-fotos/`;
const MOVIL = `${ BASE_URL }/prueba-carrusel-movil/`;

/** Real width of the downloaded file: -768x512.jpg → 768; the original → 1920. */
const fileWidth = ( src ) => {
	const match = src.match( /-(\d+)x\d+\.\w+$/ );
	return match ? Number( match[ 1 ] ) : 1920;
};
const candidates = ( srcset ) => ( srcset || '' ).split( ',' ).map( ( c ) => c.trim().split( /\s+/ )[ 0 ] ).filter( Boolean );

/** Loads `url` recording every image request with its initial priority (CDP); waits for the first slide's photo. */
async function load( newPage, url, options ) {
	const page = await newPage( options );
	const cdp = await page.context().newCDPSession( page );
	await cdp.send( 'Network.enable' );
	const requests = [];
	cdp.on( 'Network.requestWillBeSent', ( e ) => {
		if ( 'Image' === e.type || /\.(webp|jpe?g|png)(\?|$)/.test( e.request.url ) ) {
			requests.push( { url: e.request.url, priority: e.request.initialPriority } );
		}
	} );
	await page.goto( url, { waitUntil: 'load' } );
	await page.waitForFunction( () => {
		const first = [ ...document.querySelectorAll( '.estrategia-web-carousel' ) ][ 0 ]?.querySelectorAll( '.estrategia-web-carousel__track img' ) ?? [];
		return first.length && [ ...first ].slice( 0, 3 ).every( ( i ) => i.complete && i.currentSrc );
	} );
	await page.waitForTimeout( 300 ); // Late requests (a duplicated preload) still land in `requests`.
	return { page, requests };
}

/** Everything the assertions need, read in one go from the painted page. */
const inspect = ( page ) =>
	page.evaluate( () => ( {
		preloads: [ ...document.head.querySelectorAll( 'link[rel="preload"][as="image"]' ) ].map( ( l ) => ( {
			media: l.getAttribute( 'media' ),
			priority: l.getAttribute( 'fetchpriority' ),
			srcset: l.getAttribute( 'imagesrcset' ),
			sizes: l.getAttribute( 'imagesizes' ),
		} ) ),
		carousels: [ ...document.querySelectorAll( '.estrategia-web-carousel' ) ].map( ( carousel ) => ( {
			label: carousel.getAttribute( 'aria-label' ),
			images: [ ...carousel.querySelectorAll( '.estrategia-web-carousel__track img' ) ].map( ( img ) => {
				const r = img.getBoundingClientRect();
				const t = carousel.querySelector( '.estrategia-web-carousel__track' ).getBoundingClientRect();
				return {
					alt: img.alt,
					loading: img.getAttribute( 'loading' ),
					priority: img.getAttribute( 'fetchpriority' ),
					sizes: img.getAttribute( 'sizes' ),
					srcset: img.getAttribute( 'srcset' ),
					marker: img.hasAttribute( 'data-estrategia-web-carousel' ),
					src: img.currentSrc,
					natural: [ img.naturalWidth, img.naturalHeight ],
					width: r.width,
					visible: r.right > t.left + 1 && r.left < t.right - 1 && r.top < innerHeight,
					inPicture: 'PICTURE' === img.parentElement.tagName,
					media: img.parentElement.querySelector( 'source' )?.getAttribute( 'media' ),
					source: img.parentElement.querySelector( 'source' )?.getAttribute( 'srcset' ),
				};
			} ),
		} ) ),
	} ) );

/** /prueba-carrusel-fotos/ at one width: priority, deferring and the downloaded size. */
const checkFotos = ( width, { preloads, carousels: [ top, bottom ] }, errors ) => {
	assert.equal( preloads.length, 0, `${ width }: a carousel without preloadSlides got a preload` );
	assert.equal( top.label, 'Fotos arriba' );
	assert.equal( top.images.length, 4 );
	top.images.forEach( ( img, i ) => {
		assert.equal( img.marker, false, `${ img.alt }: the data-estrategia-web-carousel mark reached the page` );
		if ( i < 3 ) {
			assert.equal( img.priority, 0 === i ? 'high' : null, `${ width }: ${ img.alt } (slide ${ i + 1 }, visible on load): fetchpriority="${ img.priority }"` );
			assert.equal( img.loading, null, `${ img.alt } (visible on load) has loading="${ img.loading }"` );
		} else {
			assert.equal( img.loading, 'lazy', `${ img.alt } (not seen on load) is not deferred` );
			assert.equal( img.priority, null, `${ img.alt } (not seen on load) has fetchpriority="${ img.priority }"` );
			assert.match( img.sizes || '', /^auto, /, `${ img.alt } deferred without sizes="auto, …": ${ img.sizes }` );
		}
	} );
	const visible = top.images.filter( ( img ) => img.visible );
	assert.ok( visible.length >= 1, `at ${ width } no photo is seen` );
	for ( const img of visible ) {
		const got = fileWidth( img.src );
		assert.ok( got >= img.width, `${ width }: ${ img.alt } downloads ${ got } px for a ${ Math.round( img.width ) } slot (blurry)` );
		assert.ok( got <= img.width * 2, `${ width }: ${ img.alt } downloads ${ got } px for a ${ Math.round( img.width ) } slot (more than twice; sizes=${ img.sizes })` );
	}
	console.log( `# ${ width }: ${ visible.map( ( i ) => `${ i.alt } ${ Math.round( i.width ) }→${ fileWidth( i.src ) }` ).join( ', ' ) }` );
	assert.equal( bottom.label, 'Fotos abajo' );
	for ( const img of bottom.images ) {
		assert.equal( img.priority === 'high', false, `${ img.alt } (bottom carousel) has high priority` );
		assert.equal( img.loading, 'lazy', `${ img.alt } (bottom carousel) is not deferred` );
		assert.equal( img.marker, false );
	}
	assert.deepEqual( errors, [], errors.join( '\n' ) );
};

/** Every check of the header, on four loads made with newPage(options) (a page that records its errors in page.errors). */
export const checkImages = async ( newPage ) => {
	{
		// The three first loads at once; the desktop /movil/ after the phone one, so that it is the SECOND visit (idempotency).
		const [ fotosDesk, fotosPhone, phone ] = await Promise.all( [
			load( newPage, FOTOS, { viewport: { width: 1512, height: 900 } } ),
			load( newPage, FOTOS, { viewport: { width: 402, height: 900 } } ),
			load( newPage, MOVIL, { viewport: { width: 402, height: 874 }, deviceScaleFactor: 2 } ),
		] );
		const desk = await load( newPage, MOVIL, { viewport: { width: 1512, height: 900 } } );

		checkFotos( 1512, await inspect( fotosDesk.page ), fotosDesk.page.errors );
		checkFotos( 402, await inspect( fotosPhone.page ), fotosPhone.page.errors );

		// /movil/ at 402 (2x): the crop, in a <picture>.
		const mobile = ( await inspect( phone.page ) ).carousels[ 0 ].images;
		assert.equal( mobile.length, 3 );
		mobile.forEach( ( img, i ) => {
			assert.ok( img.inPicture, `slide ${ i + 1 } without <picture>` );
			assert.equal( img.media, '(max-width: 781px)' );
		} );
		const first = mobile[ 0 ];
		assert.equal( first.priority, 'high', 'the first one lost fetchpriority="high"' );
		// From the file NAME (…-960x1200.webp): naturalWidth comes corrected by
		// the srcset density (960w with sizes 100vw at 402 → 402).
		const [ w, h ] = ( first.src.match( /-(\d+)x(\d+)\.\w+$/ ) || [ 0, 0, 1 ] ).slice( 1 ).map( Number );
		assert.ok( Math.abs( w / h - 0.8 ) < 0.01, `at 402 a ${ w }×${ h } photo is downloaded, not the 4/5 crop (${ first.src })` );
		assert.ok( w >= first.width * 2 - 1 && w <= 1200, `the crop (${ w }) does not match the box (${ first.width } px at 2x)` );
		assert.deepEqual( phone.page.errors, [], phone.page.errors.join( '\n' ) );

		// /movil/ at 1512: the landscape photo, and the preload of slide 2.
		const { preloads, carousels } = await inspect( desk.page );
		const imgs = carousels[ 0 ].images;
		assert.ok( ! /-m\d+x\d+-/.test( imgs[ 0 ].src ) && imgs[ 0 ].natural[ 0 ] / imgs[ 0 ].natural[ 1 ] > 1.4, `on desktop ${ imgs[ 0 ].natural.join( '×' ) } is downloaded, not the landscape one (${ imgs[ 0 ].src })` );
		assert.deepEqual( imgs.map( ( i ) => i.source ), mobile.map( ( i ) => i.source ), 'the second visit serves other crop files' );
		assert.equal( preloads.length, 1, `expected one image preload, there are ${ preloads.length }` );
		const [ link ] = preloads;
		assert.equal( link.media, '(min-width: 782px)' );
		assert.equal( link.priority, 'high' );
		assert.equal( link.srcset, imgs[ 1 ].srcset, 'the preload is not slide 2\'s srcset' );
		assert.equal( link.sizes, imgs[ 1 ].sizes, 'the preload does not have slide 2\'s sizes' );
		assert.notEqual( link.srcset, imgs[ 0 ].srcset, 'slide 1 must not be preloaded (its <img> already has fetchpriority)' );
		const hits = desk.requests.filter( ( r ) => r.url === imgs[ 1 ].src );
		assert.equal( hits.length, 1, `at 1512 slide 2 (${ imgs[ 1 ].src }) is requested ${ hits.length } times` );
		assert.equal( hits[ 0 ].priority, 'High', `at 1512 slide 2 is requested at ${ hits[ 0 ].priority }, not High` );
		assert.deepEqual( desk.page.errors, [], desk.page.errors.join( '\n' ) );

		// And the phone did not download the desktop preload's files.
		const desktopFiles = new Set( candidates( link.srcset ).map( ( u ) => new globalThis.URL( u, BASE_URL ).href ) );
		assert.deepEqual( phone.requests.filter( ( r ) => desktopFiles.has( r.url ) ).map( ( r ) => r.url ), [], 'at 402 the desktop preload downloads files' );
	}
};
