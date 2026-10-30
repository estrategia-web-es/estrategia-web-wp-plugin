// NIVEL: 1 — reads the browser scenario «09-carousel-infinite»: every recorded trip of the infinite carousels arrives, never goes back and glides; the dots follow, the first peeks after the last, a Query and a two-row grid wrap, a single slide stays still. Opens no browser.
/**
 * Was sections 1-5 of carousel-infinite.test.mjs (level 3) until 29-10-2026;
 * every assertion kept. Each trip was recorded frame by frame in the page
 * (probes.js: __rec / __end) and is checked here (trips.mjs, the same numbers
 * as before): the right slide, never a step back, at least 3 frames per
 * slide travelled and no frame over half a slide. Arriving is a FACT the
 * scenario waits for. «Three clicks in a row» are three clicks with no wait
 * between them (the old test waited 90 ms: a fixed wait; the burst is still
 * three clicks while the track is gliding).
 * Seen red (28-10-2026) with the glide removed from view.min.js («only 0
 * frames between leaving and arriving»), and with the dots' goTo() emptied.
 * Seen red (29-10-2026) with the trips checked as if they had to change
 * position once (no glide), and with dot 4 expected NOT current; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';
import { checkRecordedTrip } from './trips.mjs';

const { get } = artifacts( '09-carousel-infinite' );
const c = get( null, 'desktop' );
const { trips, kept, single } = c.values;

test( 'every trip: the right slide, never back, gliding; nothing left behind and the same slides', () => {
	assert.ok( trips.length >= 20, `only ${ trips.length } trips recorded` );
	trips.forEach( checkRecordedTrip );
} );

test( '«Carrusel en bucle»: both arrows on load, no repeated ids, the dot follows, the first peeks after the last, a dot stays active', () => {
	assert.equal( kept[ 'bucle on load' ].prev, false, '"previous" comes out disabled on load' );
	assert.equal( kept[ 'bucle on load' ].next, false, '"next" comes out disabled on load' );
	assert.deepEqual( kept[ 'bucle on load' ].repeated, [], 'repeated ids (clones?)' );
	const forward = trips.filter( ( t ) => 'Carrusel en bucle' === t.name && 1 === t.distance && ! t.burst && ! t.label.includes( '(' ) );
	assert.equal( forward.length, 6, 'the six «next» of the lap' );
	for ( const t of forward ) {
		assert.equal( t.dots[ t.to ], 'true', `${ t.label }: the active dot is not number ${ t.to + 1 }` );
		if ( 4 === t.to ) {
			const [ last, first ] = t.peek;
			assert.ok( first.left > last.right - 1 && first.left - last.right < 64, `the first does not come after the last (the last ends at ${ last.right }, the first starts at ${ first.left })` );
		}
	}
	assert.equal( kept[ 'bucle dot 4' ], 'true', 'dot 4 does not stay active' );
} );

test( 'with a query: one dot per post, «previous» enabled, the active dot follows across the seam', () => {
	assert.equal( kept[ 'consulta on load' ].dots, 6, 'with a query there should be one dot per post' );
	assert.equal( kept[ 'consulta on load' ].prev, false, 'with a query, "previous" comes out disabled on load' );
	const first = trips.find( ( t ) => 'Carrusel en bucle con consulta' === t.name );
	assert.equal( first.dots[ 5 ], 'true', 'with a query, the active dot is not number 6' );
} );

test( 'the two-row grid wraps by columns and no card changes row', () => {
	const grid = Object.entries( kept ).filter( ( [ k ] ) => k.startsWith( 'grid' ) );
	assert.equal( grid.length, 6, 'the grid was not measured on load and at every column' );
	for ( const [ when, rows ] of grid ) {
		assert.equal( rows, '000111', `${ when }: the test grid is not two rows of three, or some card has changed row` );
	}
} );

test( 'a single slide: no controls to use and nothing reordered; no errors', () => {
	assert.equal( single.isStatic, true, 'with one slide the carousel does not stay static' );
	assert.equal( single.ordered, 0, 'with one slide there is nothing to reorder' );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
