// NIVEL: 1 — reads the browser scenario «11-carousel-infinite-calm»: with «reduce motion» the infinite carousels wrap around without animation and without going back. Opens no browser.
/**
 * Was section 7 of carousel-infinite.test.mjs (level 3) until 29-10-2026;
 * every assertion kept: each trip arrives, never goes back, and its position
 * changes once (trips.mjs, smooth false).
 * Seen red (29-10-2026) with the trips required to glide; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';
import { checkRecordedTrip } from './trips.mjs';

const { get } = artifacts( '11-carousel-infinite-calm' );

test( '«reduce motion»: no animation, no back-and-forth, and it wraps around the same', () => {
	const c = get( null, 'calm' );
	assert.equal( c.values.trips.length, 5 );
	for ( const t of c.values.trips ) {
		assert.equal( t.smooth, false );
		checkRecordedTrip( t );
	}
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
