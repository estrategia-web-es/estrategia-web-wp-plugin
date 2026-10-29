// NIVEL: 1 — reads the browser scenario «27-google-tags-advanced»: in advanced mode gtag.js and gtm.js are requested right away, with nothing granted. Opens no browser.
/**
 * Was test 3 of google-tags-browser.test.mjs (level 3) until 29-10-2026;
 * every assertion kept.
 * Seen red (28-10-2026) and again (29-10-2026) with another GTM id expected; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts } from '../../../tests/browser.mjs';

const { get } = artifacts( '27-google-tags-advanced' );

test( 'advanced: Google is requested RIGHT AWAY, with everything denied, before choosing', () => {
	const c = get( null, 'loaded' );
	const google = c.network.map( ( r ) => r.url ).filter( ( u ) => u.startsWith( 'https://www.googletagmanager.com/' ) );
	assert.ok( google.some( ( u ) => u.includes( 'gtag/js?id=G-TEST12345' ) ), 'gtag.js' );
	assert.ok( google.some( ( u ) => u.includes( 'gtm.js?id=GTM-ABCD123' ) ), 'gtm.js' );
	assert.ok( ! c.values.layer.some( ( e ) => 'consent' === e[ 0 ] && 'update' === e[ 1 ] && 'granted' === e[ 2 ].analytics_storage ) );
} );
