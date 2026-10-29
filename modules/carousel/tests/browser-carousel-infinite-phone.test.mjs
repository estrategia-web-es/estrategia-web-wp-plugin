// NIVEL: 1 — reads the browser scenario «10-carousel-infinite-phone»: on a phone the arrows' trips glide across the seam both ways, and a real finger wraps the carousels around. Opens no browser.
/**
 * Was section 6 of carousel-infinite.test.mjs (level 3) until 29-10-2026;
 * every assertion kept. The arrows' trips are recorded and checked as on
 * desktop (trips.mjs); each finger's arrival is a FACT the scenario waits for
 * (__settled), and fails naming it.
 * Seen red (29-10-2026) with the trips expected without gliding; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';
import { checkRecordedTrip } from './trips.mjs';

const { get } = artifacts( '10-carousel-infinite-phone' );

test( 'a phone: the arrows glide across the seam both ways; the fingers wrap around; no errors', () => {
	const c = get( null, 'phone' );
	assert.equal( c.values.trips.length, 6, 'three trips of each carousel' );
	c.values.trips.forEach( checkRecordedTrip );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
