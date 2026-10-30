// NIVEL: BANCO DE TRABAJO — not a test. What the carousel checks do with a trip recorded by modules/carousel/tests/browser/probes.js (__rec / __end): the samples are the continuous position of the track, one per frame.
//
// From carousel-infinite.test.mjs and any-count.mjs (28-10-2026), unchanged: the
// numbers that decide «it glides», «it jumps» and «it goes back» are the same.
// How to maintain it: a trip rule changes here, for every carousel check at once.
import assert from 'node:assert/strict';

/** Signed distance from a to b on a ring of n, in (-n/2, n/2]. */
export const ring = ( a, b, n ) => {
	let d = ( b - a ) % n;
	if ( d > n / 2 ) {
		d -= n;
	}
	if ( d <= -n / 2 ) {
		d += n;
	}
	return d;
};

/** The samples as a continuous path from 0 (the ring unrolled). */
export const unroll = ( samples, n ) => {
	const path = [ 0 ];
	for ( let i = 1; i < samples.length; i++ ) {
		path.push( path[ i - 1 ] + ring( samples[ i - 1 ], samples[ i ], n ) );
	}
	return path;
};

/** The worst step against `dir` between two frames (≤ 0; 0 = it never went back). */
export const worstBackStep = ( samples, n, dir ) => {
	let worst = 0;
	for ( let i = 1; i < samples.length; i++ ) {
		worst = Math.min( worst, dir * ring( samples[ i - 1 ], samples[ i ], n ) );
	}
	return worst;
};

/**
 * Checks one recorded trip of `distance` slides (negative: to the left):
 * it arrives, never goes back, and glides (smooth: ≥ 3 frames per slide
 * between leaving and arriving, no frame over half a slide) or, without
 * animation, changes position once.
 */
export const checkTrip = ( { samples, n, distance, smooth, label } ) => {
	const path = unroll( samples, n );
	const dir = Math.sign( distance );
	let worstJump = 0;
	let worstBack = 0;
	for ( let i = 1; i < path.length; i++ ) {
		const step = dir * ( path[ i ] - path[ i - 1 ] );
		worstJump = Math.max( worstJump, step );
		worstBack = Math.min( worstBack, step );
	}
	const end = path[ path.length - 1 ];
	assert.ok( Math.abs( end - distance ) < 0.03, `${ label }: travels ${ end.toFixed( 2 ) } slides and should travel ${ distance }` );
	assert.ok( worstBack > -0.03, `${ label }: in some frame it goes back ${ ( -worstBack ).toFixed( 2 ) } slides` );
	if ( smooth ) {
		const between = path.filter( ( p ) => dir * p > 0.02 && dir * p < Math.abs( distance ) - 0.02 ).length;
		assert.ok( between >= 3 * Math.abs( distance ), `${ label }: only ${ between } frames between leaving and arriving (it jumps instead of gliding)` );
		assert.ok( worstJump <= 0.5, `${ label }: one frame advances ${ worstJump.toFixed( 2 ) } slides at once (a jump)` );
	} else {
		const changes = path.filter( ( p, i ) => i && Math.abs( p - path[ i - 1 ] ) > 0.01 ).length;
		assert.ok( changes <= 2, `${ label }: without animation the position changes ${ changes } times (it should change once)` );
	}
};

/** Every check of a trip (checkTrip, and for a single trip: nothing left behind, the same number of slides). */
export const checkRecordedTrip = ( trip ) => {
	checkTrip( trip );
	if ( ! trip.burst ) {
		assert.equal( trip.ghosts, 0, `${ trip.label }: copies of the animation are left on the page` );
		assert.equal( trip.slides, trip.n, `${ trip.label }: the number of slides has changed` );
	}
};
