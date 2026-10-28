// NIVEL: BANCO DE TRABAJO — not a test file: the «any number of slides» checks that carousel.test.mjs runs (round 2, 28-10-2026: it was carousel-any-count.test.mjs, with its own reset, seed and browser). Level 3 because: what is tested is what IS SEEN frame by frame while the track scrolls across the seam (the last slide followed by the first), in a grid laid out by the browser, with keyboard and real touch gestures.
/**
 * Infinite estrategia-web/carousel with ANY number of slides (plugin 1.4.3):
 * a count that is not a multiple of what is in view, nor of the columns.
 * 1. 7 slides with 3 in view, and 23 with 4.5 in view (one row): "next" a
 *    full turn and beyond, never going back; "previous" across the seam;
 *    End and Home;
 * 2. 23 cards in a two-row grid of 12 columns (Lavandería's clients strip
 *    with its 23 clients: the bottom row has 11): "next" a full turn and
 *    beyond without ever going back (the rewind that David did not like), no
 *    card changes row, the bottom cards stay under their column, and on the
 *    keyboard ← on the first column leads to the last one moving left;
 * 3. whatever the carousel adds to fill the grid is out of the accessibility
 *    tree (aria-hidden, inert), has no ids and nothing focusable;
 * 4. on mobile, a finger dragging right on the first column shows the last;
 * 5. with "reduce motion" it wraps around the same, without animation.
 *
 * On /prueba-carrusel-impar/ (modules/carousel/tests/wp/estrategia-web-carousel-test.php).
 * Seen failing on 16-10-2026 with plugin 1.4.2: in the grid, 23 / 2 rows is
 * not a whole number of columns, the carousel did not rotate and "next" on
 * the last column rewound to the first (Lavandería: scrollLeft 2343 → 1).
 * The waits are for FACTS (which slide is at the edge), never for time.
 * Trimmed on 28-10-2026 (minimum trips that prove it): the 23-slide row and
 * the 12-column grid no longer go round all of it: End takes them straight
 * to the last one and "next" crosses the seam three times (25 and 14 trips
 * became 3 each); the 7-slide row keeps its full turn and beyond.
 * Seen red (28-10-2026) with the expected grid at 12 + 12 cards («the test
 * grid does not come out as 12 + 11»).
 */
import { assert, BASE_URL } from '../../../tests/browser.mjs';

const URL = `${ BASE_URL }/prueba-carrusel-impar/`;

/**
 * In-page probes (as in carousel-infinite.test.mjs). __slides are the REAL
 * slides in DOM (logical) order; __pos(track) is the continuous position of
 * the track measured on __row(track): the slides of the first row.
 */
const probes = () => {
	window.__slides = ( track ) =>
		[ ...track.children ].flatMap( ( c ) =>
			c.classList.contains( 'wp-block-query' ) ? [ ...c.querySelectorAll( ':scope > .wp-block-post-template > li.wp-block-post' ) ] : [ c ]
		);
	window.__row = ( track ) => ( track.closest( '.rejilla-impar' ) ? window.__slides( track ).slice( 0, 12 ) : window.__slides( track ) );
	window.__pos = ( track ) => {
		const edge = track.getBoundingClientRect().left;
		const gap = parseFloat( getComputedStyle( track ).columnGap ) || 0;
		let best = null;
		window.__row( track ).forEach( ( slide, i ) => {
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

const worstBackStep = ( samples, n, dir ) => {
	let worst = 0;
	for ( let i = 1; i < samples.length; i++ ) {
		worst = Math.min( worst, dir * ring( samples[ i - 1 ], samples[ i ], n ) );
	}
	return worst;
};

const settledAt = ( page, handle, index, n, what ) =>
	page
		.waitForFunction(
			( [ el, i, count ] ) => {
				const p = window.__pos( el );
				const d = Math.abs( ( ( ( ( p - i ) % count ) + count + count / 2 ) % count ) - count / 2 );
				const still = window.__last === el.scrollLeft;
				window.__last = el.scrollLeft;
				window.__still = d < 0.02 && still ? ( window.__still || 0 ) + 1 : 0;
				return window.__still >= 3;
			},
			[ handle, index, n ],
			{ timeout: 5000 }
		)
		.catch( async () => {
			throw new Error( `${ what }: does not reach slide ${ index + 1 } (it is at ${ ( await page.evaluate( ( el ) => window.__pos( el ), handle ) ).toFixed( 2 ) })` );
		} );

const ready = async ( page, name ) => {
	const carousel = page.getByRole( 'region', { name, exact: true } );
	await carousel.scrollIntoViewIfNeeded();
	const track = carousel.locator( '.estrategia-web-carousel__track' );
	const handle = await track.elementHandle();
	await page.waitForFunction( ( el ) => el.closest( '.estrategia-web-carousel' ).classList.contains( 'is-ready' ), handle );
	return { carousel, track, handle };
};

/** Grid layout: each real card's row (0/1) and whether each bottom card sits under its column. */
const gridLayout = ( page, handle ) =>
	page.evaluate( ( el ) => {
		const items = window.__slides( el );
		const top = Math.min( ...items.map( ( x ) => x.getBoundingClientRect().top ) );
		const rows = items.map( ( x ) => ( x.getBoundingClientRect().top < top + 2 ? 0 : 1 ) ).join( '' );
		const misplaced = items
			.slice( 12 )
			.map( ( x, c ) => Math.abs( x.getBoundingClientRect().left - items[ c ].getBoundingClientRect().left ) )
			.filter( ( d ) => d > 1 ).length;
		return { rows, misplaced };
	}, handle );

const ROWS = '0'.repeat( 12 ) + '1'.repeat( 11 );

/** Every check of the header, on three pages made with newPage(options) (a page that records its errors in page.errors). */
export const checkAnyCount = async ( newPage ) => {
	{
		// The three pages load at once (the waiting is WordPress's); they are then used one after another.
		const load = async ( options ) => {
			const p = await newPage( options );
			await p.addInitScript( probes );
			await p.goto( URL, { waitUntil: 'load' } );
			return p;
		};
		const [ page, phone, calm ] = await Promise.all( [
			load( { viewport: { width: 1200, height: 900 } } ),
			load( { viewport: { width: 402, height: 860 }, isMobile: true, hasTouch: true } ),
			load( { viewport: { width: 1200, height: 900 }, reducedMotion: 'reduce' } ),
		] );

		// 1. One row, counts that are not a multiple of what is in view. Seven: a
		// full turn and beyond. Twenty-three: End first (straight to the last
		// one), then across the seam; the middle of a 23-lap is plain scrolling.
		for ( const [ name, n ] of [ [ 'Siete de tres', 7 ], [ 'Veintitrés en una fila', 23 ] ] ) {
			const { carousel, track, handle } = await ready( page, name );
			await settledAt( page, handle, 0, n, `${ name }, on load` );
			const next = carousel.getByRole( 'button', { name: 'Diapositiva siguiente' } );
			const prev = carousel.getByRole( 'button', { name: 'Diapositiva anterior' } );
			let start = 0;
			if ( n > 7 ) {
				await track.focus();
				await page.keyboard.press( 'End' );
				await settledAt( page, handle, n - 1, n, `${ name }, End` );
				start = n - 1;
			}
			await page.evaluate( ( el ) => window.__record( el ), handle );
			for ( let k = 1; k <= ( n > 7 ? 3 : n + 2 ); k++ ) {
				await next.click();
				await settledAt( page, handle, ( start + k ) % n, n, `${ name }, "next" no. ${ k }` );
			}
			let samples = await page.evaluate( () => window.__stop() );
			assert.ok( worstBackStep( samples, n, 1 ) > -0.05, `${ name }: with "next" the track goes back ${ ( -worstBackStep( samples, n, 1 ) ).toFixed( 2 ) } slides in some frame` );
			await page.evaluate( ( el ) => window.__record( el ), handle );
			for ( const expected of [ 1, 0, n - 1, n - 2 ] ) {
				await prev.click();
				await settledAt( page, handle, expected, n, `${ name }, "previous" towards ${ expected + 1 }` );
			}
			samples = await page.evaluate( () => window.__stop() );
			assert.ok( worstBackStep( samples, n, -1 ) > -0.05, `${ name }: with "previous" the track moves forwards in some frame` );
			await track.focus();
			await page.keyboard.press( 'Home' );
			await settledAt( page, handle, 0, n, `${ name }, Home` );
			await page.keyboard.press( 'End' );
			await settledAt( page, handle, n - 1, n, `${ name }, End` );
			await page.keyboard.press( 'ArrowRight' );
			await settledAt( page, handle, 0, n, `${ name }, → on the last` );
		}

		// 2. Two-row grid with 23 cards: 12 columns, the last one with one card.
		{
			const name = 'Veintitrés en dos filas';
			const n = 12;
			const { carousel, track, handle } = await ready( page, name );
			assert.deepEqual( await gridLayout( page, handle ), { rows: ROWS, misplaced: 0 }, 'the test grid does not come out as 12 + 11' );
			await settledAt( page, handle, 0, n, `${ name }, on load` );
			const next = carousel.getByRole( 'button', { name: 'Diapositiva siguiente' } );
			// End (straight to the last column, as Lavandería's visitor scrolls), then "next"
			// across the seam: where 1.4.2 rewound (scrollLeft 2343 → 1).
			await track.focus();
			await page.keyboard.press( 'End' );
			await settledAt( page, handle, n - 1, n, `${ name }, End` );
			assert.deepEqual( await gridLayout( page, handle ), { rows: ROWS, misplaced: 0 }, `${ name }: at the last column a card changed row or left its column` );
			await page.evaluate( ( el ) => window.__record( el ), handle );
			for ( let k = 1; k <= 3; k++ ) {
				await next.click();
				await settledAt( page, handle, ( n - 1 + k ) % n, n, `${ name }, "next" no. ${ k } from the last column` );
				assert.deepEqual( await gridLayout( page, handle ), { rows: ROWS, misplaced: 0 }, `${ name }: a card changed row or left its column (column ${ ( ( n - 1 + k ) % n ) + 1 })` );
			}
			const samples = await page.evaluate( () => window.__stop() );
			assert.ok( worstBackStep( samples, n, 1 ) > -0.05, `${ name }: with "next" the track goes back ${ ( -worstBackStep( samples, n, 1 ) ).toFixed( 2 ) } columns in some frame (rewind)` );

			// ← on the first column leads to the last one, moving left.
			await track.focus();
			await page.keyboard.press( 'Home' );
			await settledAt( page, handle, 0, n, `${ name }, Home` );
			await page.evaluate( ( el ) => window.__record( el ), handle );
			await page.keyboard.press( 'ArrowLeft' );
			await settledAt( page, handle, n - 1, n, `${ name }, ← on the first` );
			assert.ok( worstBackStep( await page.evaluate( () => window.__stop() ), n, -1 ) > -0.05, `${ name }: ← on the first runs the track forwards` );
			assert.deepEqual( await gridLayout( page, handle ), { rows: ROWS, misplaced: 0 }, `${ name }: after ←, a card changed row or left its column` );

			// 3. Whatever fills the grid is out of the accessibility tree.
			const extra = await page.evaluate( ( el ) => {
				const real = new Set( window.__slides( el ) );
				const others = [ ...el.querySelectorAll( '.wp-block-post-template > li' ) ].filter( ( li ) => ! real.has( li ) );
				const ids = [ ...el.querySelectorAll( '[id]' ) ].map( ( x ) => x.id );
				return {
					exposed: others.filter( ( li ) => li.getAttribute( 'aria-hidden' ) !== 'true' || ! li.inert || li.querySelector( '[id], a, button, input, [tabindex]' ) ).length,
					duplicated: ids.filter( ( id, i ) => ids.indexOf( id ) !== i ),
				};
			}, handle );
			assert.deepEqual( extra, { exposed: 0, duplicated: [] }, `${ name }: something added to the grid is exposed to assistive technology` );
		}
		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );

		// 4. Mobile, with a finger on the grid: dragging right on the first column shows the last.
		{
			const { track, handle } = await ready( phone, 'Veintitrés en dos filas' );
			await settledAt( phone, handle, 0, 12, 'grid on mobile, on load' );
			const box = await track.boundingBox();
			const cdp = await phone.context().newCDPSession( phone );
			const y0 = Math.round( box.y + 20 );
			// The finger stops before lifting (see carousel-infinite.test.mjs).
			const swipe = async ( dx ) => {
				const x0 = Math.round( dx > 0 ? box.x + 30 : box.x + box.width - 30 );
				await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [ { x: x0, y: y0 } ] } );
				for ( let i = 1; i <= 18; i++ ) {
					await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [ { x: x0 + ( dx * Math.min( i, 12 ) ) / 12, y: y0 } ] } );
					await phone.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( resolve ) ) );
				}
				await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] } );
			};
			await swipe( 150 );
			await settledAt( phone, handle, 11, 12, 'grid on mobile, finger right on the first column' );
			await swipe( -150 );
			await settledAt( phone, handle, 0, 12, 'grid on mobile, finger left back to the first column' );
			assert.deepEqual( await gridLayout( phone, handle ), { rows: ROWS, misplaced: 0 }, 'grid on mobile: a card changed row or left its column' );
		}
		assert.deepEqual( phone.errors, [], phone.errors.join( '\n' ) );

		// 5. "Reduce motion": the grid wraps around the same, without animation.
		{
			const { track, handle } = await ready( calm, 'Veintitrés en dos filas' );
			await track.focus();
			await calm.keyboard.press( 'End' );
			await settledAt( calm, handle, 11, 12, 'grid with "reduce motion", End' );
			await calm.keyboard.press( 'ArrowRight' );
			await settledAt( calm, handle, 0, 12, 'grid with "reduce motion", → on the last column' );
			const at = await calm.evaluate( ( el ) => el.scrollLeft, handle );
			assert.ok( at > 1, `grid with "reduce motion": → on the last column rewound to scrollLeft ${ at } instead of continuing` );
		}
		assert.deepEqual( calm.errors, [], calm.errors.join( '\n' ) );
	}
};
