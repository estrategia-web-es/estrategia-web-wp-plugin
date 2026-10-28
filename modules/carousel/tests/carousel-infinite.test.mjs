// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: what is tested is what IS SEEN frame by frame while the track scrolls (which slide peeks in at the edge, which way it goes, whether it glides or jumps), with scroll-snap, keyboard and real touch gestures; none of that exists without painting the page.
/**
 * Infinite estrategia-web/carousel (0.5.0 loop attribute, true by default;
 * 0.6.0 infinite; 0.7.2 glide). Merged on 28-10-2026 from
 * carousel-infinite, carousel-glide and carousel-loop, which loaded
 * /prueba-carrusel-bucle/ seven times in three browsers and went round the
 * same carousel twice: now THREE loads (1200, a 402 phone with touch, 1200
 * with «reduce motion») and each trip recorded ONCE with every check on it.
 * What a trip must look like (checkTrip):
 * - it arrives at the right slide and never goes backwards in any frame;
 * - with animation it GLIDES: at least 3 frames between leaving and
 *   arriving per slide travelled, and no frame advances more than half a
 *   slide (a jump); with «reduce motion» the position changes once;
 * - nothing added is left on the page (the animation's copies go away), and
 *   the number of slides does not change (no clones).
 * What is checked, carousel by carousel:
 * 1. "Carrusel con una de más": THREE slides and 2.4 in view, like
 *    Lavandería's home page; up to 0.7.1 the order was changed and
 *    scrollLeft set to 0 at once: SEEN FAILING on 06-10-2026 with 0.7.1.
 *    A full lap forward plus one, back across the seam, and three "next"
 *    clicks in a row without waiting.
 * 2. "Carrusel en bucle" (five, two in view, WITHOUT the loop attribute: the
 *    default): both arrows enabled on load (seen failing on 03-10-2026 with
 *    0.4.0); a full lap forward plus one (the active dot follows the slide
 *    at the edge; on the last one the first peeks in right after it) and
 *    back across the seam; ← on the first one leads to the last moving left and → back; a dot
 *    leads to its slide; three "next" clicks in a row. Seen failing on 03-10-2026
 *    with 0.5.0: "next" at the last position went back through all of them.
 * 3. "Carrusel en bucle con consulta" (a core/query: the slides are the
 *    posts): six dots, no clones, wrapping both ways with the arrows and
 *    the keyboard.
 * 4. "Carrusel en dos filas" (the CSS a child would set, here the seed's):
 *    it wraps by columns and no card changes row (seen failing on 03-10-2026
 *    with the first infinite mode, which rotated card by card).
 * 5. "Carrusel de una": no controls and nothing reordered.
 * 6. On a phone (402): the trips glide too, and a real finger dragging right
 *    on the first one shows the last one (and back, with scroll-snap).
 * 7. With «reduce motion»: it wraps around the same, without animation.
 * Trimmed on 28-10-2026 (minimum trips that prove it): going back, a full
 * lap became «across the seam» (a backwards lap in the middle is ordinary
 * scrolling, and «never goes forward» is checked on every trip that
 * remains); on the phone the arrows cross the seam each way plus one
 * ordinary step (the lap is the desktop's job) and the «clicks in a row»
 * are gone (the burst logic does not depend on the width); the query
 * carousel crosses the seam each way (its laps were the same JavaScript as
 * the block one's) and gets two finger drags (it had five); the block one
 * gets three (across the seam each way and one ordinary step; it had five);
 * the «clicks in a row» go one way only ("previous" in a row is the same
 * queue); «reduce motion» goes forward across the seam and back across it.
 *
 * On /prueba-carrusel-bucle/ (modules/carousel/tests/wp/estrategia-web-carousel-test.php).
 * The waits are for FACTS (which slide is at the edge), never for time; the
 * only thing with time is the interval between "repeated" clicks.
 * The button names ("Diapositiva siguiente"…) are the es_ES translation the
 * test site runs in.
 * Seen red (28-10-2026, the merged file) with the glide removed from the
 * carousel's view script (view.min.js scrolling with behavior "instant":
 * «Carrusel en bucle, from 1 to 2: only 0 frames between leaving and
 * arriving»).
 */
// Round 2 (28-10-2026): «dot 1 from the last slide» is back as a trip. Seen red with the dots'
// goTo() emptied in view.min.js («Carrusel en bucle (dot 1 from the last), from 5 to 1: does not
// reach slide 1»).
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'carousel' );

const URL = `${ BASE_URL }/prueba-carrusel-bucle/`;

/**
 * In-page probes. pos(track) is the continuous position of the track: the
 * DOM index of the slide at the left edge plus the fraction already scrolled
 * past it, measured on screen. DOM order is the logical order (the carousel
 * never reorders the DOM), so it says which slide is SEEN, whatever technique
 * is used. Both functions run INSIDE the page: they cannot call this file.
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
			// The slide that covers the edge, or (in a gap) the next one.
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
				// Arrived AND still: the same position three frames in a row.
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

/** A carousel by its name, scrolled into view and hydrated, settled on its first slide. */
const open = async ( page, name, n ) => {
	const carousel = page.getByRole( 'region', { name, exact: true } );
	await carousel.scrollIntoViewIfNeeded();
	const track = carousel.locator( '.estrategia-web-carousel__track' );
	const handle = await track.elementHandle();
	await page.waitForFunction( ( el ) => el.closest( '.estrategia-web-carousel' ).classList.contains( 'is-ready' ), handle );
	if ( n ) {
		await settledAt( page, handle, 0, n, `${ name }, on load` );
	}
	return {
		page,
		name,
		n,
		carousel,
		track,
		handle,
		next: carousel.getByRole( 'button', { name: 'Diapositiva siguiente' } ),
		prev: carousel.getByRole( 'button', { name: 'Diapositiva anterior' } ),
		dots: carousel.locator( '.estrategia-web-carousel__dot' ),
		at: 0,
	};
};

/**
 * One trip recorded on its own: `act` (a click, a key) moves `dir` slides
 * from where c is. Every check of a trip (see the header) on it.
 */
const trip = async ( c, act, dir, { smooth = true, label = '' } = {} ) => {
	const { page, handle, n, carousel } = c;
	const from = c.at;
	c.at = ( ( ( c.at + dir ) % n ) + n ) % n;
	const what = `${ c.name }${ label }, from ${ from + 1 } to ${ c.at + 1 }`;
	await page.evaluate( ( el ) => window.__record( el ), handle );
	await act();
	await settledAt( page, handle, c.at, n, what );
	checkTrip( await page.evaluate( () => window.__stop() ), n, dir, smooth, what );
	assert.equal( await carousel.locator( '.estrategia-web-carousel__ghosts' ).count(), 0, `${ what }: copies of the animation are left on the page` );
	assert.equal( await page.evaluate( ( el ) => window.__slides( el ).length, handle ), n, `${ what }: the number of slides has changed` );
};

/** Three clicks in a row without waiting: one recording, three slides. */
const burst = async ( c, button, dir ) => {
	const { page, handle, n, name } = c;
	await page.evaluate( ( el ) => window.__record( el ), handle );
	for ( let k = 0; k < 3; k++ ) {
		await button.click();
		await page.waitForTimeout( 90 );
	}
	c.at = ( ( ( c.at + 3 * dir ) % n ) + n ) % n;
	const what = `${ name }, three "${ dir > 0 ? 'next' : 'previous' }" in a row`;
	await settledAt( page, handle, c.at, n, what );
	checkTrip( await page.evaluate( () => window.__stop() ), n, 3 * dir, true, what );
};

const newProbedPage = async ( newPage, options ) => {
	const page = await newPage( options );
	await page.addInitScript( probes );
	await page.goto( URL, { waitUntil: 'load' } );
	return page;
};

test( 'carousel-infinite', () =>
	withBrowser( async ( { newPage } ) => {
		// The three pages load at once (the waiting is WordPress's); they are then used one after another,
		// so no animation is measured while another page is busy.
		const [ page, phone, calm ] = await Promise.all( [
			newProbedPage( newPage, { viewport: { width: 1200, height: 900 } } ),
			newProbedPage( newPage, { viewport: { width: 402, height: 860 }, isMobile: true, hasTouch: true } ),
			newProbedPage( newPage, { viewport: { width: 1200, height: 900 }, reducedMotion: 'reduce' } ),
		] );

		// 1. Three slides, 2.4 in view: a full lap forward and back across the seam, gliding; three "next" in a row.
		{
			const c = await open( page, 'Carrusel con una de más', 3 );
			for ( let k = 0; k < 4; k++ ) await trip( c, () => c.next.click(), 1 );
			for ( let k = 0; k < 2; k++ ) await trip( c, () => c.prev.click(), -1 );
			await burst( c, c.next, 1 );
		}

		// 2. Five, two in view, the loop attribute by default.
		{
			const c = await open( page, 'Carrusel en bucle', 5 );
			assert.equal( await c.prev.isDisabled(), false, '"previous" comes out disabled on load' );
			assert.equal( await c.next.isDisabled(), false, '"next" comes out disabled on load' );
			const ids = await page.evaluate( ( el ) => [ ...el.querySelectorAll( '[id]' ) ].map( ( x ) => x.id ), c.handle );
			assert.deepEqual( ids.filter( ( id, i ) => ids.indexOf( id ) !== i ), [], 'repeated ids (clones?)' );
			for ( let k = 1; k <= 6; k++ ) {
				await trip( c, () => c.next.click(), 1 );
				assert.equal( await c.dots.nth( c.at ).getAttribute( 'aria-current' ), 'true', `the active dot is not number ${ c.at + 1 }` );
				if ( 4 === c.at ) {
					// On the last one, the first one peeks in right after it, to its right.
					const [ last, first ] = await page.evaluate( ( el ) => [ window.__slides( el )[ 4 ], window.__slides( el )[ 0 ] ].map( ( s ) => s.getBoundingClientRect().toJSON() ), c.handle );
					assert.ok( first.left > last.right - 1 && first.left - last.right < 64, `the first does not come after the last (the last ends at ${ last.right }, the first starts at ${ first.left })` );
				}
			}
			for ( let k = 0; k < 2; k++ ) await trip( c, () => c.prev.click(), -1 ); // From 2 to 1, and across the seam to 5.
			// Dot 1 from the last slide (across the seam), recorded as a trip. Its settling is waited for
			// BEFORE the track gets the focus below: Playwright's focus() scrolls the track into view, and
			// in the middle of the glide that cancels the move (measured 28-10-2026: focus 0 ms after the
			// click leaves slide 5 and dot 5 for good; 300 ms after, or focus({ preventScroll: true }),
			// slide 1). That is the test's doing, not the dots'.
			await trip( c, () => c.dots.nth( 0 ).click(), 1, { label: ' (dot 1 from the last)' } );
			// Keyboard: ← on the first leads to the last, moving left; → goes back.
			await c.track.focus();
			c.at = 0;
			await settledAt( page, c.handle, 0, 5, 'Carrusel en bucle, back on the first' );
			await trip( c, () => page.keyboard.press( 'ArrowLeft' ), -1, { label: ' (←)' } );
			await trip( c, () => page.keyboard.press( 'ArrowRight' ), 1, { label: ' (→)' } );
			// A dot leads to its slide and stays active.
			await c.dots.nth( 3 ).click();
			await settledAt( page, c.handle, 3, 5, 'Carrusel en bucle, dot 4' );
			assert.equal( await c.dots.nth( 3 ).getAttribute( 'aria-current' ), 'true', 'dot 4 does not stay active' );
			await c.dots.nth( 0 ).click();
			await settledAt( page, c.handle, 0, 5, 'Carrusel en bucle, dot 1' );
			c.at = 0;
			await burst( c, c.next, 1 );
		}

		// 3. With a query: six posts, six dots, wrapping both ways (arrows and keyboard).
		{
			const c = await open( page, 'Carrusel en bucle con consulta', 6 );
			assert.equal( await c.dots.count(), 6, 'with a query there should be one dot per post' );
			assert.equal( await c.prev.isDisabled(), false, 'with a query, "previous" comes out disabled on load' );
			await trip( c, () => c.prev.click(), -1 );
			assert.equal( await c.dots.nth( 5 ).getAttribute( 'aria-current' ), 'true', 'with a query, the active dot is not number 6' );
			await trip( c, () => c.next.click(), 1 );
			await c.track.focus();
			await trip( c, () => page.keyboard.press( 'ArrowLeft' ), -1, { label: ' (← across the seam)' } );
			await trip( c, () => page.keyboard.press( 'ArrowRight' ), 1, { label: ' (→ across the seam)' } );
		}

		// 4. Two-row grid: the unit is the COLUMN and no card changes row when wrapping around.
		{
			const grid = await open( page, 'Carrusel en dos filas' );
			const rowsOf = () =>
				page.evaluate( ( el ) => {
					const items = ( window.__allSlides || window.__slides )( el );
					const top = Math.min( ...items.map( ( x ) => x.getBoundingClientRect().top ) );
					return items.map( ( x ) => ( x.getBoundingClientRect().top < top + 2 ? 0 : 1 ) ).join( '' );
				}, grid.handle );
			assert.equal( await rowsOf(), '000111', 'the test grid does not come out as two rows of three' );
			// The first three are on top: the position is measured on them (n = 3).
			await page.evaluate( () => {
				const all = window.__slides;
				window.__allSlides = all;
				window.__slides = ( el ) => ( el.closest( '.rejilla-prueba' ) ? all( el ).slice( 0, 3 ) : all( el ) );
			} );
			for ( const [ button, expected ] of [ [ grid.next, 1 ], [ grid.next, 2 ], [ grid.next, 0 ], [ grid.prev, 2 ], [ grid.prev, 1 ] ] ) {
				await button.click();
				await settledAt( page, grid.handle, expected, 3, `grid, towards column ${ expected + 1 }` );
				assert.equal( await rowsOf(), '000111', `grid: some card has changed row (column ${ expected + 1 })` );
			}
			await page.evaluate( () => {
				window.__slides = window.__allSlides;
			} );
		}

		// 5. A single slide: no controls and the order is not touched.
		const single = page.getByRole( 'region', { name: 'Carrusel de una', exact: true } );
		await single.scrollIntoViewIfNeeded();
		assert.ok( await single.evaluate( ( el ) => el.classList.contains( 'is-static' ) ), 'with one slide the carousel does not stay static' );
		assert.equal( await single.locator( '.estrategia-web-carousel__track > [style*="order"]' ).count(), 0, 'with one slide there is nothing to reorder' );
		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );

		// 6. A phone: the arrows glide too, and a real finger wraps around.
		for ( const [ name, n ] of [ [ 'Carrusel con una de más', 3 ], [ 'Carrusel en bucle', 5 ] ] ) {
			const c = await open( phone, name, n );
			// Across the seam both ways, and one ordinary step.
			await trip( c, () => c.prev.click(), -1, { label: ' on mobile' } );
			await trip( c, () => c.next.click(), 1, { label: ' on mobile' } );
			await trip( c, () => c.next.click(), 1, { label: ' on mobile' } );
		}
		for ( const [ name, n, swipes ] of [ [ 'Carrusel en bucle', 5, [ 260, -260, -260 ] ], [ 'Carrusel en bucle con consulta', 6, [ 260, -260 ] ] ] ) {
			const c = await open( phone, name );
			// Back on the first one (the arrows above left it elsewhere).
			const at = Math.round( await phone.evaluate( ( el ) => window.__pos( el ), c.handle ) ) % n;
			if ( at !== 0 ) {
				await c.dots.nth( 0 ).click();
				await settledAt( phone, c.handle, 0, n, `${ name } on mobile, dot 1` );
			}
			const box = await c.track.boundingBox();
			// A real finger: touchStart, moves one frame each, and touchEnd. The
			// trap (measured on 03-10-2026): Input.synthesizeScrollGesture with
			// "touch" moves nothing in windowless Chromium, and back-to-back
			// touchMoves without waiting a frame scroll but do not finish the
			// gesture (neither snap nor scrollend). And the finger STOPS before
			// lifting (six frames still): if it lifts while moving, windowless
			// Chromium starts an inertia (fling) that never ends, one gesture in
			// two is left halfway and it looks like a carousel bug.
			const cdp = await phone.context().newCDPSession( phone );
			const y0 = Math.round( box.y + box.height / 2 );
			const swipe = async ( dx ) => {
				const x0 = Math.round( dx > 0 ? box.x + 30 : box.x + box.width - 30 );
				await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [ { x: x0, y: y0 } ] } );
				for ( let i = 1; i <= 18; i++ ) {
					await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [ { x: x0 + ( dx * Math.min( i, 12 ) ) / 12, y: y0 } ] } );
					await phone.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( resolve ) ) );
				}
				await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] } );
			};
			let expected = 0;
			for ( const dx of swipes ) {
				expected = ( expected + ( dx > 0 ? -1 : 1 ) + n ) % n;
				await swipe( dx );
				await settledAt( phone, c.handle, expected, n, `${ name } on mobile, finger ${ dx > 0 ? 'right' : 'left' } towards ${ expected + 1 }` );
			}
		}
		assert.deepEqual( phone.errors, [], phone.errors.join( '\n' ) );

		// 7. "Reduce motion": no animation, no back-and-forth, and it wraps around the same.
		{
			const c = await open( calm, 'Carrusel con una de más', 3 );
			for ( let k = 0; k < 3; k++ ) await trip( c, () => c.next.click(), 1, { smooth: false, label: ' with "reduce motion"' } ); // 1, 2, and across the seam.
			await trip( c, () => c.prev.click(), -1, { smooth: false, label: ' with "reduce motion"' } ); // Back across the seam.
		}
		{
			const c = await open( calm, 'Carrusel en bucle', 5 );
			await c.dots.nth( 4 ).click();
			await settledAt( calm, c.handle, 4, 5, 'with "reduce motion", dot 5' );
			c.at = 4;
			await trip( c, () => c.next.click(), 1, { smooth: false, label: ' with "reduce motion", "next" on the last' } );
		}
		assert.deepEqual( calm.errors, [], calm.errors.join( '\n' ) );
	} )
);
