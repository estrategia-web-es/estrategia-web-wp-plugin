// NIVEL: 1 — reads the browser scenario «28-language-switcher»: on an English page the switcher opens, closes with Escape giving the focus back, and a new visitor sees the cookie notice. Opens no browser.
/**
 * Was language-switcher-browser.test.mjs (level 3) until 29-10-2026; every
 * assertion kept. What is tested is the block's JavaScript (view.js, the
 * Interactivity API), which does not know WPML: the page is read with the
 * WPML double in English. What only real WPML gives (the translation's URL,
 * <html lang>, the notice's markup in /en/) is language-switcher.test.mjs
 * (level 2, real WPML).
 * How to maintain: the selectors are the block's (render.php); they change in
 * the scenario and in language-switcher.test.mjs at once.
 * Seen red (29-10-2026) with the focus expected AWAY from the button after
 * Escape, and with aria-expanded expected "false" after pressing; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '28-language-switcher' );

test( 'a new visitor of the English page sees the cookie notice; the list is hidden until pressed', () => {
	const v = get( null, 'closed' ).values;
	assert.ok( v.noticeSeen, 'a new visitor of the English page does not see the cookie notice' );
	assert.equal( v.lang, 'EN', 'the double did not put the page in English' );
	assert.equal( v.listSeen, false, 'the list is seen before opening the switcher' );
} );

test( 'pressing opens it; Escape closes it and gives the focus back to the button; no errors', () => {
	const open = get( null, 'open' ).values;
	assert.equal( open.expanded, 'true', 'pressing does not turn aria-expanded true' );
	assert.ok( open.listSeen, 'pressing does not show the list' );
	const escaped = get( null, 'escaped' );
	assert.equal( escaped.values.expanded, 'false', 'Escape does not close the switcher' );
	assert.ok( escaped.values.focusBack, 'after Escape the focus does not return to the button' );
	const errors = errorsOf( escaped );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
