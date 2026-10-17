// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: which srcset photo is downloaded is decided by the browser with the sizes and the painted width; without painting there is no currentSrc.
/**
 * estrategia-web/carousel with photos (0.7.4), on /prueba-carrusel-fotos/:
 * - the TOP carousel (1/2/3 in view, four photos): the first three slides,
 *   the ones that can be seen on load, have fetchpriority="high" and none has
 *   loading="lazy"; the fourth, which is not seen, is deferred
 *   (loading="lazy", no priority, with sizes="auto, …").
 * - the BOTTOM one (3000 px further down): no photo with priority, all
 *   deferred. Priority is not given away to a carousel that is not in view.
 * - at 1512 and 402 wide, the downloaded photo of each visible slide is at
 *   least its painted width and no more than twice: the sizes gives the
 *   slide's width, not the window's.
 * - the internal mark data-estrategia-web-carousel does not reach the page.
 * Against 0.7.3 it fails: only the first one had priority, the fourth was not
 * deferred and at 1512 it downloaded the 1536 one for a ~500 slot.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'carousel' );

const URL = `${ BASE_URL }/prueba-carrusel-fotos/`;

/** Real width of the downloaded file: -768x512.jpg → 768; the original → 1920. */
const fileWidth = ( src ) => {
	const match = src.match( /-(\d+)x\d+\.\w+$/ );
	return match ? Number( match[ 1 ] ) : 1920;
};

const inspect = ( page ) =>
	page.evaluate( () =>
		[ ...document.querySelectorAll( '.estrategia-web-carousel' ) ].map( ( carousel ) => ( {
			label: carousel.getAttribute( 'aria-label' ),
			images: [ ...carousel.querySelectorAll( '.estrategia-web-carousel__track img' ) ].map( ( img ) => ( {
				alt: img.alt,
				loading: img.getAttribute( 'loading' ),
				priority: img.getAttribute( 'fetchpriority' ),
				sizes: img.getAttribute( 'sizes' ),
				marker: img.hasAttribute( 'data-estrategia-web-carousel' ),
				src: img.currentSrc,
				width: img.getBoundingClientRect().width,
				visible: ( () => {
					const r = img.getBoundingClientRect();
					const t = carousel.querySelector( '.estrategia-web-carousel__track' ).getBoundingClientRect();
					return r.right > t.left + 1 && r.left < t.right - 1 && r.top < innerHeight;
				} )(),
			} ) ),
		} ) )
	);

test( 'carousel-images', () =>
	withBrowser( async ( { newPage } ) => {
		for ( const width of [ 1512, 402 ] ) {
			const page = await newPage( { viewport: { width, height: 900 } } );
			await page.goto( URL, { waitUntil: 'load' } );
			await page.waitForFunction( () =>
				[ ...document.querySelectorAll( '.estrategia-web-carousel' ) ][ 0 ]
					.querySelectorAll( 'img' )
					.length && [ ...document.querySelectorAll( '.estrategia-web-carousel' )[ 0 ].querySelectorAll( 'img' ) ].slice( 0, 3 ).every( ( i ) => i.complete && i.currentSrc )
			);
			const [ top, bottom ] = await inspect( page );
			assert.equal( top.label, 'Fotos arriba' );
			assert.equal( top.images.length, 4 );

			top.images.forEach( ( img, i ) => {
				assert.equal( img.marker, false, `${ img.alt }: the data-estrategia-web-carousel mark reached the page` );
				if ( i < 3 ) {
					// 0.7.9: "high" ONLY the first one; the other visible ones load now, without priority.
					// (The test was left at 0.7.4 and had been red since 06-10-2026;
					// it was brought up to date on 14-10-2026, checked against 1.0.0 untouched.)
					assert.equal( img.priority, 0 === i ? 'high' : null, `${ img.alt } (slide ${ i + 1 }, visible on load): fetchpriority="${ img.priority }"` );
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
			assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
		}
	} )
);
