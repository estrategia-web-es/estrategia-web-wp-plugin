// NIVEL: 1 — reads the browser scenario «23-google-tags-ads-event»: the form conversion by JS event happens only with Advertising accepted. Opens no browser.
/**
 * Was the «by event» visitor of test 2 of google-tags-browser.test.mjs
 * (level 3) until 29-10-2026; every assertion kept.
 * Seen red (29-10-2026) with analytics expected «granted» after accepting
 * (the site does not ask about Analytics); restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts } from '../../../tests/browser.mjs';

const { get } = artifacts( '23-google-tags-ads-event' );

test( 'the form conversion by JS event only with Advertising accepted', () => {
	assert.equal( get( null, 'no-consent' ).values.conversions.length, 0, 'without consent there is no conversion' );
	const { update, conversions } = get( null, 'accepted' ).values;
	assert.equal( update.ad_storage, 'granted' );
	assert.equal( update.ad_user_data, 'granted' );
	assert.equal( update.ad_personalization, 'granted' );
	assert.equal( update.analytics_storage, 'denied', 'the site does not ask about Analytics' );
	assert.deepEqual( conversions[ 0 ]?.[ 2 ], { send_to: 'AW-123456789/AbCd_ef-12' } );
} );
