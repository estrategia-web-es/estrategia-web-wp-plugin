// NIVEL: 1 — reads the browser scenario «05-carousel-crop-desktop»: at 1512 the carousel downloads the landscape photo, and the head carries one preload of slide 2 with its srcset and sizes, downloaded once at High. Opens no browser.
/**
 * Was the /prueba-carrusel-movil/ desktop load of ./images.mjs (run by
 * carousel.test.mjs, level 3) until 29-10-2026; every assertion kept. The
 * priority is Chromium's initial priority of the request, recorded by the
 * runner (CDP); the capture waited until every photo request had finished,
 * so a late or duplicated request is in the network record.
 * Seen red (16-10-2026, 28-10-2026) with preload_config() never preloading:
 * «expected one image preload, there are 0». Seen red (29-10-2026) with
 * slide 3 expected preloaded; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '05-carousel-crop-desktop' );

test( 'at 1512: the landscape photo, and the preload of slide 2, downloaded once at High', () => {
	const c = get( null, 'desktop' );
	const { preloads, carousels } = c.values.inspect;
	const imgs = carousels[ 0 ].images;
	assert.ok( ! /-m\d+x\d+-/.test( imgs[ 0 ].src ) && imgs[ 0 ].natural[ 0 ] / imgs[ 0 ].natural[ 1 ] > 1.4, `on desktop ${ imgs[ 0 ].natural.join( '×' ) } is downloaded, not the landscape one (${ imgs[ 0 ].src })` );
	assert.equal( preloads.length, 1, `expected one image preload, there are ${ preloads.length }` );
	const [ link ] = preloads;
	assert.equal( link.media, '(min-width: 782px)' );
	assert.equal( link.priority, 'high' );
	assert.equal( link.srcset, imgs[ 1 ].srcset, 'the preload is not slide 2\'s srcset' );
	assert.equal( link.sizes, imgs[ 1 ].sizes, 'the preload does not have slide 2\'s sizes' );
	assert.notEqual( link.srcset, imgs[ 0 ].srcset, 'slide 1 must not be preloaded (its <img> already has fetchpriority)' );
	const hits = c.network.filter( ( r ) => r.url === imgs[ 1 ].src );
	assert.equal( hits.length, 1, `at 1512 slide 2 (${ imgs[ 1 ].src }) is requested ${ hits.length } times` );
	assert.equal( hits[ 0 ].priority, 'High', `at 1512 slide 2 is requested at ${ hits[ 0 ].priority }, not High` );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
