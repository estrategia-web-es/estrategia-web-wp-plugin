// NIVEL: 1 — reads the browser scenario «08-carousel-any-count-calm»: with «reduce motion» the 12 + 11 grid wraps from the last column to the first without rewinding. Opens no browser.
/**
 * Was section 5 of ./any-count.mjs (run by carousel.test.mjs, level 3) until
 * 29-10-2026. End reaching the last column and → reaching the first are
 * FACTS of the scenario; here, that it got there by going on, not by
 * rewinding the track to scrollLeft 0.
 * Seen red (29-10-2026) expecting scrollLeft 0; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '08-carousel-any-count-calm' );

test( '«reduce motion»: → on the last column continues instead of rewinding', () => {
	const c = get( null, 'calm' );
	assert.ok( c.values.scrollLeft > 1, `grid with "reduce motion": → on the last column rewound to scrollLeft ${ c.values.scrollLeft } instead of continuing` );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
