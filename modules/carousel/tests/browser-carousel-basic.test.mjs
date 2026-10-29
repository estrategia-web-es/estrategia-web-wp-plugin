// NIVEL: 1 — reads the browser scenario «01-carousel-basic»: the carousel hydrates as a «carrusel» region, moves with the arrows, the keyboard and the dots, and disables the arrows at the ends. Opens no browser.
/**
 * Was the first test of carousel.test.mjs (level 3) until 29-10-2026. Every
 * movement assertion of the old test was a wait for a fact (scrollLeft past
 * 50 px, «previous» enabled, → moving on, End disabling «next», «previous»
 * going back, dot 1 at 0 and marked): they are the scenario's
 * waitForExpression steps, and one that never holds fails the scenario —
 * and this check — naming it. Here, what was read on load, and the console.
 * Seen red (28-10-2026) with the dots' goTo() emptied in view.min.js, and
 * with aria-roledescription expected at «carousel». Seen red (29-10-2026)
 * the same way (the description); restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '01-carousel-basic' );

test( 'carousel: arrows, keyboard (← → End) and dots; the arrows get disabled at the ends', () => {
	const loaded = get( null, 'loaded' ).values;
	assert.equal( loaded.roledescription, 'carrusel' );
	assert.equal( loaded.prevDisabled, true, 'at the start, "previous" should be disabled' );
	const errors = errorsOf( get( null, 'back' ) );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
