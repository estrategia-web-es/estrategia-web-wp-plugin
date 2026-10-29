// NIVEL: 1 — reads the browser scenario «22-google-tags-ga4»: basic GA4 asks Google for nothing before choosing nor with maps only, loads gtag.js and grants only analytics on accepting, loads it again on return, and withdrawing denies, deletes _ga and stops it. Opens no browser.
/**
 * Was test 1 of google-tags-browser.test.mjs (level 3) until 29-10-2026;
 * every assertion kept. The fixed waits of the old test (300 ms after «maps
 * only», 200 ms after withdrawing) are FACTS in the scenario: the consent
 * event fired (window.seen; the google-tags script reacts inside its
 * listener, so dataLayer already says what the choice did), and the last
 * update denies analytics.
 * Seen failing on 14-10-2026 with load() always called (basic mode requested
 * Google before accepting) and the analytics update tied to marketing. Seen
 * red (29-10-2026) with ad_storage expected «granted» with Analytics
 * accepted; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '22-google-tags-ga4' );
const google = ( label ) => get( null, label ).network.filter( ( r ) => r.url.startsWith( 'https://www.googletagmanager.com/' ) ).map( ( r ) => r.url );

test( 'before choosing: the «default» first, everything denied, nothing to Google', () => {
	const { first } = get( null, 'start' ).values;
	assert.deepEqual( first.slice( 0, 2 ), [ 'consent', 'default' ], 'the «default» is the FIRST thing in dataLayer' );
	assert.equal( first[ 2 ].analytics_storage, 'denied' );
	assert.equal( first[ 2 ].ad_storage, 'denied' );
	assert.deepEqual( google( 'start' ), [], 'no choice yet' );
} );

test( 'maps only is not Analytics', () => {
	assert.deepEqual( google( 'maps-only' ), [], 'maps only is not Analytics' );
	assert.equal( get( null, 'maps-only' ).values.update?.analytics_storage, 'denied' );
} );

test( 'accept all: gtag.js with the id, ONLY analytics_storage granted', () => {
	const c = get( null, 'accepted' );
	assert.ok( google( 'accepted' ).some( ( u ) => u.startsWith( 'https://www.googletagmanager.com/gtag/js?id=G-TEST12345' ) ), google( 'accepted' ).join( '\n' ) );
	assert.equal( c.values.update.analytics_storage, 'granted' );
	assert.equal( c.values.update.ad_storage, 'denied', 'the site does not ask about Advertising: it is not granted' );
	assert.ok( c.values.layer.some( ( e ) => 'config' === e[ 0 ] && 'G-TEST12345' === e[ 1 ] ) );
} );

test( 'on returning, with the choice saved, it loads without clicking anything', () => {
	assert.equal( google( 'returned' ).length, 1 );
} );

test( 'withdrawing Analytics denies and deletes _ga and _ga_*; the next page asks Google for nothing', () => {
	const c = get( null, 'withdrawn' );
	assert.equal( c.values.update.analytics_storage, 'denied' );
	const left = c.cookies.map( ( x ) => x.name ).filter( ( n ) => n.startsWith( '_ga' ) );
	assert.deepEqual( left, [], 'Google cookies remain after withdrawing consent' );
	assert.deepEqual( google( 'after-rejecting' ), [], 'rejecting' );
	const errors = errorsOf( get( null, 'after-rejecting' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
