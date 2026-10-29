// NIVEL: 1 — reads the browser scenario «07-carousel-any-count-phone»: on a phone, a finger on the first column of the 12 + 11 grid shows the last one, and back, without moving any card. Opens no browser.
/**
 * Was section 4 of ./any-count.mjs (run by carousel.test.mjs, level 3) until
 * 29-10-2026. Reaching the last column after the finger to the right, and the
 * first after the finger to the left, are FACTS the scenario waits for (and
 * fails naming); here, the layout afterwards and the console.
 * Seen red (29-10-2026) with the grid expected at 12 + 12; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '07-carousel-any-count-phone' );

test( 'mobile, with a finger on the grid: the last column and back, no card moved', () => {
	const c = get( null, 'phone' );
	assert.deepEqual( c.values.layout, { rows: '0'.repeat( 12 ) + '1'.repeat( 11 ), misplaced: 0 }, 'grid on mobile: a card changed row or left its column' );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
