// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: which file is downloaded (the vertical crop or the landscape photo) is decided by the browser with the <source>'s media and the window width; without painting there is no currentSrc.
/**
 * The carousel's mobile crop (Estrategia Web plugin 1.1.x, mobileRatio
 * attribute; modules/carousel/includes/carousel-mobile-crop.php), on
 * /prueba-carrusel-movil/ (1920×1280 photos in a carousel with mobileRatio
 * "4/5"):
 * - each <img> is inside a <picture> with a <source media="(max-width: 781px)">;
 * - at 402 px (2x) the downloaded photo is the CROP: 4/5 ratio, as wide as the
 *   box asks for (≥ 804 px), and not the 1536 landscape one;
 * - at 1512 px the usual landscape one is downloaded;
 * - the first one keeps fetchpriority="high" (priority belongs to the <img>,
 *   whatever source applies) and the page gives no errors;
 * - idempotent: the second visit serves the same files (it does not remake
 *   them under another name).
 * Seen red (14-10-2026) before carousel-mobile-crop.php was written: there was
 * no <picture> and at 402 it downloaded the 1536 one.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'carousel' );

const URL = `${ BASE_URL }/prueba-carrusel-movil/`;

const inspect = ( page ) =>
	page.evaluate( () =>
		[ ...document.querySelectorAll( '.estrategia-web-carousel__track img' ) ].map( ( img ) => ( {
			inPicture: 'PICTURE' === img.parentElement.tagName,
			media: img.parentElement.querySelector( 'source' )?.getAttribute( 'media' ),
			srcset: img.parentElement.querySelector( 'source' )?.getAttribute( 'srcset' ),
			priority: img.getAttribute( 'fetchpriority' ),
			src: img.currentSrc,
			natural: [ img.naturalWidth, img.naturalHeight ],
			box: img.getBoundingClientRect().width,
		} ) )
	);

const loaded = ( page ) =>
	page.waitForFunction( () => {
		const first = document.querySelector( '.estrategia-web-carousel__track img' );
		return first && first.complete && first.naturalWidth > 0;
	} );

test( 'carousel-mobile-crop', () =>
	withBrowser( async ( { newPage } ) => {
		const phone = await newPage( { viewport: { width: 402, height: 874 }, deviceScaleFactor: 2 } );
		await phone.goto( URL, { waitUntil: 'load' } );
		await loaded( phone );
		const mobile = await inspect( phone );
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
		assert.ok( w >= first.box * 2 - 1 && w <= 1200, `the crop (${ w }) does not match the box (${ first.box } px at 2x)` );
		assert.deepEqual( phone.errors, [], phone.errors.join( '\n' ) );

		const again = await newPage( { viewport: { width: 402, height: 874 }, deviceScaleFactor: 2 } );
		await again.goto( URL, { waitUntil: 'load' } );
		assert.deepEqual( ( await inspect( again ) ).map( ( i ) => i.srcset ), mobile.map( ( i ) => i.srcset ), 'the second visit serves other files' );

		const desk = await newPage( { viewport: { width: 1512, height: 900 } } );
		await desk.goto( URL, { waitUntil: 'load' } );
		await loaded( desk );
		const [ wide ] = await inspect( desk );
		assert.ok( ! /-m\d+x\d+-/.test( wide.src ) && wide.natural[ 0 ] / wide.natural[ 1 ] > 1.4, `on desktop ${ wide.natural.join( '×' ) } is downloaded, not the landscape one (${ wide.src })` );
		assert.deepEqual( desk.errors, [], desk.errors.join( '\n' ) );
	} )
);
