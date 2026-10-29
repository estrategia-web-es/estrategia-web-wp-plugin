// NIVEL: 1 — reads the browser scenario «02-carousel-query»: with a Query inside, each post is a slide (two per row, one dot per post), the arrow advances one post and End reaches the last. Opens no browser.
/**
 * Was the second test of carousel.test.mjs (level 3) until 29-10-2026. The
 * movements (one post per arrow, End disabling «next» with the sixth dot
 * marked, dot 2 leading to its post) are facts the scenario waits for; here,
 * what was read on load.
 * Seen red (28-10-2026) with the expected dot count at 5; seen red again
 * (29-10-2026) the same way; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '02-carousel-query' );

test( 'carousel with a query: each post is a slide (two per row, one dot per post), the arrow advances one post, End reaches the last', () => {
	const v = get( null, 'loaded' ).values;
	assert.equal( v.slides, 6, 'the query should bring six posts' );
	assert.ok( v.boxes[ 0 ].width <= v.trackWidth / 2 + 1, `the post is ${ v.boxes[ 0 ].width }px wide in a ${ v.trackWidth }px track` );
	assert.ok( Math.abs( v.boxes[ 1 ].top - v.boxes[ 0 ].top ) < 2, 'the posts are not in a row (they stack)' );
	assert.ok( v.boxes[ 1 ].left > v.boxes[ 0 ].left, 'the second post is not to the right of the first' );
	assert.equal( v.dots, 6, 'there should be one dot per post' );
	const errors = errorsOf( get( null, 'moved' ) );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
