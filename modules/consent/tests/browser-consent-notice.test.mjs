// NIVEL: 1 — reads the browser scenario «12-consent-notice»: one visitor's journey through the cookie notice — first visit, reject, the parent's cookie, the link, accept. Opens no browser.
/**
 * Was consent-browser.test.mjs (level 3) until 29-10-2026; every assertion
 * kept, in the same four steps. What the notice is: the header of
 * includes/class-consent.php; what the server prints is consent.test.mjs.
 * Two changes in HOW, not in what:
 * - «Tab leaves it (no trap)»: the old test pressed Tab until the focus left,
 *   at most once per focusable element plus one. The scenario puts the focus
 *   on the notice's LAST visible focusable element and presses Tab once: with
 *   a trap the focus would cycle back inside, so it proves the same with one
 *   key and no loop.
 * - The waits are FACTS of the scenario (consent-ready fired, the notice
 *   hidden or shown, the focus on the title, the deferred script ran); a fact
 *   that never comes fails the scenario naming it, and this check with it.
 * The steps depend on each other on purpose: read the FIRST failure.
 * Seen failing on 05-10-2026 with six mutants (rel with nofollow, another
 * revision, no alias, no consent event, no consent-ready, «Powered by» in
 * #aaa: contrast 2.3:1). Seen red (29-10-2026) with the parent's revision
 * computed with '|2' (2: «unexpected cookie») and the contrast threshold at
 * 7:1; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '12-consent-notice' );
const md5 = ( s ) => crypto.createHash( 'md5' ).update( s ).digest( 'hex' ).slice( 0, 8 );
const REVISION = md5( 'statistics,maps|1' ); // The armazon-wp-padre 0.6.0 formula.
const v = ( label ) => get( null, label ).values;

/** WCAG contrast between two rgb()/rgba() colours (the foreground without alpha). */
function contrast( fg, bg ) {
	const parse = ( s ) => s.match( /[\d.]+/g ).slice( 0, 3 ).map( Number );
	const lum = ( [ r, g, b ] ) => {
		const c = [ r, g, b ].map( ( x ) => {
			x /= 255;
			return x <= 0.03928 ? x / 12.92 : ( ( x + 0.055 ) / 1.055 ) ** 2.4;
		} );
		return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
	};
	const [ a, b ] = [ lum( parse( fg ) ), lum( parse( bg ) ) ].sort( ( x, y ) => y - x );
	return ( a + 0.05 ) / ( b + 0.05 );
}

test( '1. first visit: consent-ready once with null, Accept and Reject weigh the same, «Powered by» visible, smaller, AA', () => {
	const first = v( 'first' );
	assert.deepEqual( first.ready, [ [ null, 'function' ] ], 'consent-ready fires ONCE on boot, also without a choice (null), with the API already set' );
	assert.equal( first.accept, first.reject, 'accept and reject do not weigh the same' );
	const m = first.powered;
	assert.ok( m?.seen, 'the «Powered by» link is not visible' );
	assert.ok( /- link "Powered by Estrategia Web/.test( get( null, 'first' ).aria() ), 'no link named «Powered by Estrategia Web…» in the accessibility tree' );
	assert.equal( m.rel, 'noopener' );
	assert.equal( m.target, '_blank' );
	assert.equal( m.opacity, '1', 'the line must not have opacity (the measured contrast would no longer hold)' );
	assert.ok( ! /rgba\(.*,\s*0?\.\d+\)$/.test( m.color ), `colour with transparency: ${ m.color }` );
	const ratio = contrast( m.color, m.bg );
	assert.ok( ratio >= 4.5, `contrast ${ ratio.toFixed( 2 ) }:1 (${ m.color } on ${ m.bg }); AA requires 4.5:1` );
	assert.ok( m.size < m.buttonSize, `the line (${ m.size }px) is not smaller than the buttons (${ m.buttonSize }px)` );
} );

test( '1. 0.5.1: at least 16 px between the text and the buttons, at 402 and 1512', () => {
	for ( const width of [ 402, 1512 ] ) {
		const { gap } = v( `gap-${ width }` );
		assert.ok( gap.gap >= 16, `${ width }: between the last line of text and the buttons there are ${ gap.gap.toFixed( 1 ) } px` );
		assert.ok( gap.titleGap >= 4, `${ width }: title and text stuck together (${ gap.titleGap.toFixed( 1 ) } px)` );
	}
} );

test( '1. 0.5.1: the FIRST thing when tabbing; Tab leaves it (no trap); Shift+Tab comes back; Escape does not close it without choosing', () => {
	assert.ok( v( 'first-tab' ).inside, `the first Tab goes to ${ v( 'first-tab' ).focused }, not to the notice` );
	assert.equal( v( 'tab-out' ).inside, false, 'Tab from the notice\'s last element stays inside the notice: focus trap' );
	assert.ok( v( 'tab-out' ).noticeSeen, 'leaving with Tab must not close the notice' );
	assert.ok( v( 'shift-tab' ).inside, 'Shift+Tab does not go back to the notice' );
	assert.ok( v( 'escape-unchosen' ).noticeSeen, 'the first time, Escape does not close it: a choice is required' );
} );

test( '1. the JS contract: categories, get() null, has("necessary"); grant() adds ONE declared category, fires the event, leaves the notice open', () => {
	const before = v( 'escape-unchosen' );
	assert.deepEqual( before.categories, [ 'statistics', 'maps' ] );
	assert.equal( before.get, null, 'with no choice, get() is null' );
	assert.equal( before.necessary, true );
	const granted = v( 'granted' );
	assert.deepEqual( granted.seen, [ [ 'maps' ] ] );
	assert.deepEqual( granted.get, [ 'maps' ] );
	assert.ok( granted.noticeSeen, 'grant does not close the notice' );
	assert.deepEqual( v( 'undeclared' ).get, [ 'maps' ], 'an undeclared category is not granted' );
} );

test( '2. «Rechazar todas»: the parent\'s cookie format and revision; the parent\'s own cookie is still valid', () => {
	const rejected = get( null, 'rejected' );
	const cookie = rejected.cookies.find( ( c ) => 'estrategia_web_consent' === c.name );
	assert.ok( cookie, 'the estrategia_web_consent cookie was not stored' );
	assert.match( decodeURIComponent( cookie.value ), new RegExp( `^${ REVISION }\\.\\.\\d+$` ), `unexpected cookie: ${ cookie.value }` );
	assert.equal( rejected.values.statistics, 'undefined', 'a rejected statistics script ran' );
	const parent = v( 'parent-cookie' );
	assert.equal( parent.noticeSeen, false, 'the notice shows again to someone who already chose (with the parent\'s notice)' );
	assert.deepEqual( parent.get, [ 'maps' ] );
	assert.deepEqual( parent.ready, [ [ [ 'maps' ], 'function' ] ], 'consent-ready fires once with the stored choice' );
	assert.equal( parent.statistics, 'undefined', 'statistics was not accepted: its script must not run' );
} );

test( '3 and 4. the link reopens it (focus inside, Escape closes it); «Aceptar todas» runs the deferred script; on return the event fires on boot and the choices are ticked', () => {
	// 3 is in the scenario as FACTS (the notice shown, the focus on its title, hidden after Escape).
	const accepted = v( 'accepted' );
	assert.deepEqual( accepted.last, [ 'statistics', 'maps' ] );
	assert.equal( accepted.statistics, true );
	const returned = v( 'returned' );
	assert.equal( returned.noticeSeen, false, 'the notice shows again after accepting' );
	assert.deepEqual( returned.seen, [ [ 'statistics', 'maps' ] ], 'the event fires on boot if there was already a choice' );
	const reopened = get( null, 'reopened' );
	assert.equal( reopened.values.statistics.checked, true, 'open() does not show «statistics» ticked' );
	assert.match( reopened.values.statistics.label, /Analítica/, '1.1.0: «statistics» is labelled «Analítica» in es_ES' );
	const errors = errorsOf( reopened, { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
