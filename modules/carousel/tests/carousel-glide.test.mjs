// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested is whether moving from one slide to the next IS SEEN gliding or jumping, frame by frame, with the track painted, scroll-snap and real animations.
/**
 * Infinite estrategia-web/carousel: each arrow GLIDES (0.7.2). On every step,
 * with "next" and with "previous", also when wrapping around (from the last to
 * the first) and when clicking repeatedly without waiting:
 * 1. between the leaving slide and the arriving one there are SEVERAL
 *    intermediate frames (at least 3): it does not change at once;
 * 2. no frame advances more than half a slide over the previous one (a jump)
 *    nor goes backwards;
 * 3. when it stops, the right slide is at the edge, and nothing added is left
 *    on the page (the animation's copies go away).
 * With "reduce motion" there is no animation, but no back-and-forth either:
 * the position changes once and stays at the right one.
 *
 * On /prueba-carrusel-bucle/ (modules/carousel/tests/wp/estrategia-web-carousel-test.php):
 * - "Carrusel con una de más": THREE slides and 2.4 in view, like
 *   Lavandería's home page (photos of the warehouse). No rotation of the row
 *   lets a whole slide scroll, and up to 0.7.1 the order was changed and
 *   scrollLeft was set to 0 at once: SEEN FAILING on 06-10-2026 with 0.7.1
 *   (the position goes from 0 to 1 in one frame, with nothing in between).
 *   David: "the carousel needs an animation, it moves in jumps".
 * - "Carrusel en bucle": five and two in view; here there is scrolling and
 *   the test guards that it keeps gliding, also when clicking repeatedly.
 * The waits are for FACTS (which slide is at the edge), never for time; the
 * only thing with time is the interval between "repeated" clicks.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'carousel' );

const URL = `${ BASE_URL }/prueba-carrusel-bucle/`;

/**
 * In-page probes (the same idea as carousel-infinite.test.mjs): pos(track) is
 * the DOM index of the slide at the left edge plus the fraction scrolled past
 * it, measured on screen, so it says what is SEEN whatever the technique
 * (scroll, order, transforms).
 */
const probes = () => {
	window.__slides = ( track ) =>
		[ ...track.children ].flatMap( ( c ) =>
			c.classList.contains( 'wp-block-query' ) ? [ ...c.querySelectorAll( ':scope > .wp-block-post-template > li' ) ] : [ c ]
		);
	window.__pos = ( track ) => {
		const edge = track.getBoundingClientRect().left;
		const gap = parseFloat( getComputedStyle( track ).columnGap ) || 0;
		let best = null;
		window.__slides( track ).forEach( ( slide, i ) => {
			const r = slide.getBoundingClientRect();
			if ( r.right + gap > edge + 0.5 && ( ! best || r.left < best.r.left ) ) {
				best = { i, r };
			}
		} );
		return best.i + ( edge - best.r.left ) / ( best.r.width + gap );
	};
	window.__record = ( track ) => {
		window.__samples = [];
		const tick = () => {
			window.__samples.push( window.__pos( track ) );
			window.__frame = requestAnimationFrame( tick );
		};
		tick();
	};
	window.__stop = () => {
		cancelAnimationFrame( window.__frame );
		return window.__samples;
	};
};

/** Signed distance from a to b on a ring of n, in (-n/2, n/2]. */
const ring = ( a, b, n ) => {
	let d = ( b - a ) % n;
	if ( d > n / 2 ) d -= n;
	if ( d <= -n / 2 ) d += n;
	return d;
};

/** The samples as a continuous path from 0 (the ring unrolled). */
const unroll = ( samples, n ) => {
	const path = [ 0 ];
	for ( let i = 1; i < samples.length; i++ ) {
		path.push( path[ i - 1 ] + ring( samples[ i - 1 ], samples[ i ], n ) );
	}
	return path;
};

const settledAt = ( page, handle, index, n, what ) =>
	page
		.waitForFunction(
			( [ el, i, count ] ) => {
				const p = window.__pos( el );
				const d = Math.abs( ( ( ( ( p - i ) % count ) + count + count / 2 ) % count ) - count / 2 );
				const still = window.__last === p;
				window.__last = p;
				window.__still = d < 0.02 && still ? ( window.__still || 0 ) + 1 : 0;
				return window.__still >= 3;
			},
			[ handle, index, n ],
			{ timeout: 5000 }
		)
		.catch( async () => {
			throw new Error( `${ what }: does not reach slide ${ index + 1 } (it is at ${ ( await page.evaluate( ( el ) => window.__pos( el ), handle ) ).toFixed( 2 ) })` );
		} );

/**
 * Checks one recorded trip of `distance` slides (negative: to the left).
 * smooth: whether it must glide (frames in between) or be instant.
 */
const checkTrip = ( samples, n, distance, smooth, what ) => {
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
	assert.ok( Math.abs( end - distance ) < 0.03, `${ what }: travels ${ end.toFixed( 2 ) } slides and should travel ${ distance }` );
	assert.ok( worstBack > -0.03, `${ what }: in some frame it goes back ${ ( -worstBack ).toFixed( 2 ) } slides` );
	if ( smooth ) {
		const between = path.filter( ( p ) => dir * p > 0.02 && dir * p < Math.abs( distance ) - 0.02 ).length;
		assert.ok( between >= 3 * Math.abs( distance ), `${ what }: only ${ between } frames between leaving and arriving (it jumps instead of gliding)` );
		assert.ok( worstJump <= 0.5, `${ what }: one frame advances ${ worstJump.toFixed( 2 ) } slides at once (a jump)` );
	} else {
		const changes = path.filter( ( p, i ) => i && Math.abs( p - path[ i - 1 ] ) > 0.01 ).length;
		assert.ok( changes <= 2, `${ what }: without animation the position changes ${ changes } times (it should change once)` );
	}
};

const open = async ( page, name ) => {
	const carousel = page.getByRole( 'region', { name, exact: true } );
	await carousel.scrollIntoViewIfNeeded();
	const handle = await carousel.locator( '.estrategia-web-carousel__track' ).elementHandle();
	await page.waitForFunction( ( el ) => el.closest( '.estrategia-web-carousel' ).classList.contains( 'is-ready' ), handle );
	return {
		carousel,
		handle,
		next: carousel.getByRole( 'button', { name: 'Diapositiva siguiente' } ),
		prev: carousel.getByRole( 'button', { name: 'Diapositiva anterior' } ),
	};
};

/** One click per trip, each recorded on its own: a full lap forward and back. */
const lap = async ( page, name, n, smooth, label ) => {
	const { carousel, handle, next, prev } = await open( page, name );
	await settledAt( page, handle, 0, n, `${ label }, on load` );
	let at = 0;
	for ( const [ button, dir, times ] of [ [ next, 1, n + 1 ], [ prev, -1, n + 1 ] ] ) {
		for ( let k = 0; k < times; k++ ) {
			const from = at;
			at = ( at + dir + n ) % n;
			await page.evaluate( ( el ) => window.__record( el ), handle );
			await button.click();
			await settledAt( page, handle, at, n, `${ label }, from ${ from + 1 } to ${ at + 1 }` );
			const samples = await page.evaluate( () => window.__stop() );
			checkTrip( samples, n, dir, smooth, `${ label }, from ${ from + 1 } to ${ at + 1 }` );
			assert.equal( await carousel.locator( '.estrategia-web-carousel__ghosts' ).count(), 0, `${ label }: copies of the animation are left on the page` );
			assert.equal( await page.evaluate( ( el ) => window.__slides( el ).length, handle ), n, `${ label }: the number of slides has changed` );
		}
	}
	return { handle, next, prev, at };
};

/** Three clicks in a row without waiting: one recording, three slides. */
const burst = async ( page, name, n, label ) => {
	const { handle, next, prev } = await open( page, name );
	await settledAt( page, handle, 0, n, `${ label }, before clicking repeatedly` );
	for ( const [ button, dir ] of [ [ next, 1 ], [ prev, -1 ] ] ) {
		const start = Math.round( await page.evaluate( ( el ) => window.__pos( el ), handle ) ) % n;
		await page.evaluate( ( el ) => window.__record( el ), handle );
		for ( let k = 0; k < 3; k++ ) {
			await button.click();
			await page.waitForTimeout( 90 );
		}
		const end = ( start + 3 * dir + n * 3 ) % n;
		await settledAt( page, handle, end, n, `${ label }, three "${ dir > 0 ? 'next' : 'previous' }" in a row` );
		const samples = await page.evaluate( () => window.__stop() );
		checkTrip( samples, n, 3 * dir, true, `${ label }, three "${ dir > 0 ? 'next' : 'previous' }" in a row` );
	}
};

test( 'carousel-glide', () =>
	withBrowser( async ( { newPage } ) => {
		for ( const [ width, suffix ] of [ [ 1200, '' ], [ 402, ' on mobile' ] ] ) {
			const page = await newPage( { viewport: { width, height: 900 } } );
			await page.addInitScript( probes );
			await page.goto( URL, { waitUntil: 'networkidle' } );
			for ( const [ name, n ] of [ [ 'Carrusel con una de más', 3 ], [ 'Carrusel en bucle', 5 ] ] ) {
				await lap( page, name, n, true, name + suffix );
				await burst( page, name, n, name + suffix );
			}
			assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
		}

		// "Reduce motion": no animation and no back-and-forth.
		const calm = await newPage( { viewport: { width: 1200, height: 900 }, reducedMotion: 'reduce' } );
		await calm.addInitScript( probes );
		await calm.goto( URL, { waitUntil: 'networkidle' } );
		await lap( calm, 'Carrusel con una de más', 3, false, 'With "reduce motion"' );
		assert.deepEqual( calm.errors, [], calm.errors.join( '\n' ) );
	} )
);
