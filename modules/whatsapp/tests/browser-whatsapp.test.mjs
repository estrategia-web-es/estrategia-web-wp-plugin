// NIVEL: 1 — reads the browser scenario «42-whatsapp»: the WhatsApp links, new tab, accessible name, fixed in its corner with the safe area, still while scrolling and under the cookie notice at 1280 and 402. Opens no browser.
/**
 * Was whatsapp.test.mjs (level 3) until 29-10-2026; every assertion kept,
 * now on what the scenario saved (modules/whatsapp/tests/browser/42-whatsapp.json):
 * 1. the wa.me link is correct with attributes (number with spaces and «+»,
 *    message with accents) and with the site option (a block without
 *    attributes); it opens in a new tab, has an accessible name (aria.yml,
 *    the accessibility tree), is fixed, is at least 44 px, and each goes to
 *    its corner. Since 0.7.1 the distance to the corner adds the mobile safe
 *    area (env(safe-area-inset-*)): checked in the stylesheet, because in a
 *    desktop browser env() is 0 and the computed style does not tell it apart;
 * 2. at 1280 and 402 the button is in the SAME place at the top of the page,
 *    halfway and at the end (really fixed), and with the notice open, where
 *    the two overlap, the NOTICE is on top (at 402 they must overlap, or
 *    this proves nothing).
 * The trap (in the scenario): every scroll is behavior «instant»; with
 * scroll-behavior: smooth on the root a plain scrollTo() animates.
 * Seen red (29-10-2026) expecting «button on top» where they overlap (2) and
 * the site option's message changed (1); restored, green.
 * How to maintain: if the notice or the button change their z-index or
 * position, 2 says whether they still stack right.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '42-whatsapp' );
const top = get( null, 'top' );

test( '1. the two buttons: links, new tab, accessible name, fixed, 44 px, each in its corner, the safe area', () => {
	const buttons = top.boxes.buttons;
	assert.equal( buttons.length, 2, 'two were expected: the one with attributes and the one without' );
	const names = top.aria().split( '\n' ).filter( ( l ) => /- link "[^"]*Escríbenos por WhatsApp/.test( l ) );
	assert.equal( names.length, 2, `two links named «Escríbenos por WhatsApp…» in the accessibility tree:\n${ top.aria() }` );
	const hrefs = buttons.map( ( b ) => b.attrs.href );
	assert.ok( hrefs.includes( 'https://wa.me/34600111222?text=' + encodeURIComponent( 'Hola, ¿tenéis hora mañana?' ) ), `the link with attributes is missing: ${ hrefs.join( ' ' ) }` );
	assert.ok( hrefs.includes( 'https://wa.me/34611223344?text=' + encodeURIComponent( 'Mensaje desde la opción del sitio' ) ), `the site option's link is missing: ${ hrefs.join( ' ' ) }` );
	for ( const b of buttons ) {
		assert.equal( b.attrs.target, '_blank' );
		assert.match( b.attrs.rel, /noopener/ );
		assert.equal( b.styles.position, 'fixed' );
		assert.ok( b.w >= 44 && b.h >= 44, 'touch target smaller than 44 px' );
	}
	const left = buttons.find( ( b ) => /is-position-bottom-left/.test( b.attrs.class ) );
	const right = buttons.find( ( b ) => /is-position-bottom-right/.test( b.attrs.class ) );
	assert.ok( left.x < 100 && right.x > 1000, `unexpected positions: ${ left.x } / ${ right.x }` );
	const insets = top.values.insets;
	const insetOf = ( selector, prop ) => insets[ `${ selector } ${ prop }` ] || '';
	assert.match( insetOf( '.estrategia-web-whatsapp', 'inset-block-end' ), /safe-area-inset-bottom/, JSON.stringify( insets ) );
	assert.match( insetOf( '.estrategia-web-whatsapp.is-position-bottom-left', 'inset-inline-start' ), /safe-area-inset-left/, JSON.stringify( insets ) );
	assert.match( insetOf( '.estrategia-web-whatsapp.is-position-bottom-right', 'inset-inline-end' ), /safe-area-inset-right/, JSON.stringify( insets ) );
} );

test( '2. fixed while scrolling, and the notice on top where they overlap, at 1280 and 402', () => {
	const bad = [];
	for ( const [ width, prefix ] of [ [ 1280, '' ], [ 402, 'phone-' ] ] ) {
		const start = get( null, `${ prefix }top` ).values;
		assert.ok( start.box, `${ width }: no WhatsApp button` );
		assert.ok( start.long, `${ width }: the page is not long enough to scroll` );
		for ( const at of [ 'half', 'end' ] ) {
			const now = get( null, `${ prefix }${ at }` ).values.box;
			if ( JSON.stringify( now ) !== JSON.stringify( start.box ) ) {
				bad.push( `${ width }: it moves when scrolling (${ JSON.stringify( start.box ) } → ${ JSON.stringify( now ) })` );
			}
		}
		if ( 'button on top' === start.covered || 'no notice' === start.covered ) {
			bad.push( `${ width }: with the cookie notice open → ${ start.covered }` );
		}
		if ( 402 === width && 'no overlap' === start.covered ) {
			bad.push( '402: the notice and the button do not overlap, so this checks nothing (did the layout change?)' );
		}
	}
	assert.deepEqual( bad, [], `WhatsApp and the notice:\n${ bad.join( '\n' ) }` );
	const errors = errorsOf( get( null, 'phone-end' ) );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
