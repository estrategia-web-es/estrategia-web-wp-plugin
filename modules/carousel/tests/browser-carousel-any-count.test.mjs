// NIVEL: 1 — reads the browser scenario «06-carousel-any-count»: a carousel with any number of slides never goes back across the seam, in a row or in a 12 + 11 grid that keeps its rows and columns, and what fills the grid is out of the accessibility tree. Opens no browser.
/**
 * Was sections 1-3 of ./any-count.mjs (run by carousel.test.mjs, level 3)
 * until 29-10-2026; every assertion kept. Arriving at each slide is a FACT
 * the scenario waits for (__settled: that slide at the edge, still three
 * frames); the frames between are recorded (probes.js) and checked here:
 * never a step back of more than 0.05 slides. Seen failing on 16-10-2026 with
 * plugin 1.4.2: in the grid, 23 / 2 rows is not a whole number of columns,
 * and «next» on the last column rewound to the first (scrollLeft 2343 → 1).
 * Seen red (28-10-2026) and again (29-10-2026) with the grid expected at
 * 12 + 12 cards; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';
import { worstBackStep } from './trips.mjs';

const { get } = artifacts( '06-carousel-any-count' );
const c = get( null, 'desktop' );
const ROWS = '0'.repeat( 12 ) + '1'.repeat( 11 );

test( 'one row and the grid: «next» and «previous» never go back in any frame', () => {
	assert.equal( c.values.trips.length, 6, 'six recorded journeys (7 and 23 each way, the grid «next» and its ←)' );
	for ( const t of c.values.trips ) {
		const back = worstBackStep( t.samples, t.n, Math.sign( t.distance ) );
		assert.ok( back > -0.05, `${ t.label }: the track goes ${ t.distance > 0 ? 'back' : 'forwards' } ${ ( -back ).toFixed( 2 ) } slides in some frame` );
	}
} );

test( 'the grid stays 12 + 11: no card changes row or leaves its column', () => {
	for ( const [ when, layout ] of Object.entries( c.values.layouts ) ) {
		assert.deepEqual( layout, { rows: ROWS, misplaced: 0 }, `Veintitrés en dos filas, ${ when }: the test grid does not come out as 12 + 11, or a card changed row or left its column` );
	}
	assert.ok( Object.keys( c.values.layouts ).length >= 5, 'the grid layout was not measured at every stop' );
} );

test( 'what fills the grid is out of the accessibility tree; no errors', () => {
	assert.deepEqual( c.values.extra, { exposed: 0, duplicated: [] }, 'something added to the grid is exposed to assistive technology' );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
