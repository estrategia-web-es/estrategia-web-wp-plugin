// NIVEL: 1 — reads the browser scenario «04-carousel-crop-phone»: on a 402 px 2x phone the carousel downloads the 4/5 crop inside a <picture>, keeps the first one's priority, downloads none of the desktop preload's files, and a second visit serves the same crop files. Opens no browser.
/**
 * Was the /prueba-carrusel-movil/ phone load of ./images.mjs (run by
 * carousel.test.mjs, level 3) until 29-10-2026; every assertion kept. The
 * desktop preload's files are read from THIS page's own <link rel="preload">
 * (the server prints it with media="(min-width: 782px)" for every visitor):
 * the phone must not download them. Idempotency: the scenario's second visit
 * (it was the desktop visit) must serve the same crop files.
 * Seen red (14-10-2026) before carousel-mobile-crop.php was written: there
 * was no <picture> and at 402 it downloaded the 1536 one. Seen red
 * (29-10-2026) with the crop ratio expected at 3/4; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get, base } = artifacts( '04-carousel-crop-phone' );
const candidates = ( srcset ) => ( srcset || '' ).split( ',' ).map( ( c ) => c.trim().split( /\s+/ )[ 0 ] ).filter( Boolean );

test( 'at 402 (2x): the crop, in a <picture>, the first with priority', () => {
	const c = get( null, 'phone' );
	const mobile = c.values.inspect.carousels[ 0 ].images;
	assert.equal( mobile.length, 3 );
	mobile.forEach( ( img, i ) => {
		assert.ok( img.inPicture, `slide ${ i + 1 } without <picture>` );
		assert.equal( img.media, '(max-width: 781px)' );
	} );
	const first = mobile[ 0 ];
	assert.equal( first.priority, 'high', 'the first one lost fetchpriority="high"' );
	// From the file NAME (…-960x1200.webp): naturalWidth comes corrected by the srcset density.
	const [ w, h ] = ( first.src.match( /-(\d+)x(\d+)\.\w+$/ ) || [ 0, 0, 1 ] ).slice( 1 ).map( Number );
	assert.ok( Math.abs( w / h - 0.8 ) < 0.01, `at 402 a ${ w }×${ h } photo is downloaded, not the 4/5 crop (${ first.src })` );
	assert.ok( w >= first.width * 2 - 1 && w <= 1200, `the crop (${ w }) does not match the box (${ first.width } px at 2x)` );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );

test( 'the phone does not download the desktop preload\'s files', () => {
	const c = get( null, 'phone' );
	const [ link ] = c.values.inspect.preloads;
	assert.ok( link, 'the page has no image preload to compare with (preloadSlides 2)' );
	const desktopFiles = new Set( candidates( link.srcset ).map( ( u ) => new URL( u, base ).href ) );
	assert.deepEqual( c.network.filter( ( r ) => desktopFiles.has( r.url ) ).map( ( r ) => r.url ), [], 'at 402 the desktop preload downloads files' );
} );

test( 'idempotent: the second visit serves the same crop files', () => {
	const first = get( null, 'phone' ).values.inspect.carousels[ 0 ].images.map( ( i ) => i.source );
	const second = get( null, 'second-visit' ).values.inspect.carousels[ 0 ].images.map( ( i ) => i.source );
	assert.deepEqual( second, first, 'the second visit serves other crop files' );
} );
