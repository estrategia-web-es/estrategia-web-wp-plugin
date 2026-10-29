// NIVEL: 1 — reads the browser scenario «03-carousel-photos»: at 1512 and 402 the first visible photo alone has priority, the rest are deferred, each downloaded photo fits its slide, and there is no preload. Opens no browser.
/**
 * Was /prueba-carrusel-fotos/ in ./images.mjs (run by carousel.test.mjs,
 * level 3) until 29-10-2026; every assertion kept, at both widths (the
 * scenario's two units). Against 0.7.3 it fails: only the first had
 * priority, the fourth was not deferred and at 1512 it downloaded the 1536
 * one for a ~500 slot.
 * Seen red (29-10-2026) with fetchpriority="high" expected on slide 2; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '03-carousel-photos' );
/** Real width of the downloaded file: -768x512.jpg → 768; the original → 1920. */
const fileWidth = ( src ) => {
	const match = src.match( /-(\d+)x\d+\.\w+$/ );
	return match ? Number( match[ 1 ] ) : 1920;
};

for ( const width of [ 1512, 402 ] ) {
	test( `${ width }: priority, deferring and the downloaded size`, () => {
		const c = get( width, 'photos' );
		const { preloads, carousels: [ top, bottom ] } = c.values.inspect;
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
		assert.equal( bottom.label, 'Fotos abajo' );
		for ( const img of bottom.images ) {
			assert.equal( img.priority === 'high', false, `${ img.alt } (bottom carousel) has high priority` );
			assert.equal( img.loading, 'lazy', `${ img.alt } (bottom carousel) is not deferred` );
			assert.equal( img.marker, false );
		}
		const errors = errorsOf( c );
		assert.deepEqual( errors, [], errors.join( '\n' ) );
	} );
}
