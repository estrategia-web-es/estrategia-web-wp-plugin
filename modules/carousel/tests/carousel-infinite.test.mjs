// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested is what IS SEEN frame by frame while the track scrolls (which slide peeks in at the edge and which way it goes), with scroll-snap, keyboard and real touch gestures; none of that exists without painting the page.
/**
 * Infinite estrategia-web/carousel (0.6.0, loop attribute, true by default):
 * 1. "next" goes all the way round WITHOUT ever going back: in no frame does
 *    the track move backwards, and after the last one the first one peeks in
 *    right after it (to its right);
 * 2. "previous" the same in the opposite direction, and the keyboard ← on the
 *    first one leads to the last one moving left;
 * 3. the active dot is always the one of the slide at the left edge, and
 *    clicking a dot leads to its slide;
 * 4. there are no clones: as many slides in the DOM as posts or blocks, and
 *    no repeated id;
 * 5. on mobile, dragging right with a finger on the first one shows the last
 *    one (real touch gesture, with scroll-snap);
 * 6. with "reduce motion" it wraps around the same way (without animation);
 * 7. with ONE slide there are no controls and nothing is touched;
 * 8. in a two-row grid (the CSS a child would set; here the seed's) it wraps around by columns and no
 *    card changes row.
 * The same with a core/query inside (the slides are the posts).
 *
 * On /prueba-carrusel-bucle/ (modules/carousel/tests/wp/estrategia-web-carousel-test.php).
 * Seen failing on 03-10-2026 with 0.5.0: clicking "next" at the last
 * position made the track go back through all of them.
 * The waits are for FACTS (which slide is at the edge), never for time.
 * The button names ("Diapositiva siguiente"…) are the es_ES translation the
 * test site runs in.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'carousel' );

const URL = `${ BASE_URL }/prueba-carrusel-bucle/`;

/**
 * In-page probes. pos(track) is the continuous position of the track: the
 * DOM index of the slide at the left edge plus the fraction already scrolled
 * past it. DOM order is the logical order (the carousel never reorders the
 * DOM), so it says which slide is SEEN, whatever technique is used.
 */
const probes = () => {
	const slides = ( track ) =>
		[ ...track.children ].flatMap( ( c ) =>
			c.classList.contains( 'wp-block-query' ) ? [ ...c.querySelectorAll( ':scope > .wp-block-post-template > li' ) ] : [ c ]
		);
	window.__slides = slides;
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

/** Every frame moves in direction dir (or stays): returns the worst step back. */
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
				// Arrived AND still: the same position three frames in a row.
				const p = window.__pos( el );
				const d = Math.abs( ( ( ( p - i ) % count ) + count + count / 2 ) % count - count / 2 );
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

test( 'carousel-infinite', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage( { viewport: { width: 1200, height: 900 } } );
		await page.addInitScript( probes );
		await page.goto( URL, { waitUntil: 'networkidle' } );

		for ( const [ name, n ] of [ [ 'Carrusel en bucle', 5 ], [ 'Carrusel en bucle con consulta', 6 ] ] ) {
			const carousel = page.getByRole( 'region', { name, exact: true } );
			const track = carousel.locator( '.estrategia-web-carousel__track' );
			const prev = carousel.getByRole( 'button', { name: 'Diapositiva anterior' } );
			const next = carousel.getByRole( 'button', { name: 'Diapositiva siguiente' } );
			const dots = carousel.locator( '.estrategia-web-carousel__dot' );
			const handle = await track.elementHandle();
			await carousel.scrollIntoViewIfNeeded();
			await page.waitForFunction( ( el ) => el.closest( '.estrategia-web-carousel' ).classList.contains( 'is-ready' ), handle );
			await settledAt( page, handle, 0, n, `${ name }, on load` );

			// 4. No clones.
			const census = await page.evaluate( ( el ) => {
				const ids = [ ...el.querySelectorAll( '[id]' ) ].map( ( x ) => x.id );
				return { slides: window.__slides( el ).length, duplicated: ids.filter( ( id, i ) => ids.indexOf( id ) !== i ) };
			}, handle );
			assert.equal( census.slides, n, `${ name }: there are ${ census.slides } slides in the DOM and there should be ${ n } (clones?)` );
			assert.deepEqual( census.duplicated, [], `${ name }: repeated ids` );

			// 1. "Next" n times: a full turn forwards, without going back.
			await page.evaluate( ( el ) => window.__record( el ), handle );
			for ( let k = 1; k <= n; k++ ) {
				await next.click();
				await settledAt( page, handle, k % n, n, `${ name }, "next" no. ${ k }` );
				assert.equal( await dots.nth( k % n ).getAttribute( 'aria-current' ), 'true', `${ name }: the active dot is not number ${ ( k % n ) + 1 }` );
				if ( k === n - 1 ) {
					// On the last one, the first one peeks in right after it, to its right.
					const [ last, first ] = await page.evaluate(
						( [ el, count ] ) => [ window.__slides( el )[ count - 1 ], window.__slides( el )[ 0 ] ].map( ( s ) => s.getBoundingClientRect().toJSON() ),
						[ handle, n ]
					);
					assert.ok( first.left > last.right - 1 && first.left - last.right < 64, `${ name }: the first does not come after the last (the last ends at ${ last.right }, the first starts at ${ first.left })` );
				}
			}
			let samples = await page.evaluate( () => window.__stop() );
			assert.ok( worstBackStep( samples, n, 1 ) > -0.05, `${ name }: with "next" the track goes back ${ ( -worstBackStep( samples, n, 1 ) ).toFixed( 2 ) } slides in some frame` );

			// 2. "Previous" n times: the turn in reverse.
			await page.evaluate( ( el ) => window.__record( el ), handle );
			for ( let k = 1; k <= n; k++ ) {
				await prev.click();
				await settledAt( page, handle, ( n - k ) % n, n, `${ name }, "previous" no. ${ k }` );
			}
			samples = await page.evaluate( () => window.__stop() );
			assert.ok( worstBackStep( samples, n, -1 ) > -0.05, `${ name }: with "previous" the track moves forwards in some frame` );

			// 2b. Keyboard: ← on the first one leads to the last one, moving left.
			await track.focus();
			await page.evaluate( ( el ) => window.__record( el ), handle );
			await page.keyboard.press( 'ArrowLeft' );
			await settledAt( page, handle, n - 1, n, `${ name }, ← on the first` );
			samples = await page.evaluate( () => window.__stop() );
			assert.ok( worstBackStep( samples, n, -1 ) > -0.05, `${ name }: ← on the first one runs the track forwards` );
			await page.keyboard.press( 'ArrowRight' );
			await settledAt( page, handle, 0, n, `${ name }, → on the last` );

			// 3. A dot leads to its slide and stays active.
			await dots.nth( 3 ).click();
			await settledAt( page, handle, 3, n, `${ name }, dot 4` );
			assert.equal( await dots.nth( 3 ).getAttribute( 'aria-current' ), 'true', `${ name }: dot 4 does not stay active` );
			await dots.nth( 0 ).click();
			await settledAt( page, handle, 0, n, `${ name }, dot 1` );
		}

		// 8. Two-row grid (Lavandería's client strip): the unit is the COLUMN
		// and no card changes row when wrapping around.
		// Seen failing on 03-10-2026 with the first version of the infinite
		// mode, which rotated card by card: the top row's cards dropped to the
		// bottom row.
		const grid = page.getByRole( 'region', { name: 'Carrusel en dos filas', exact: true } );
		await grid.scrollIntoViewIfNeeded();
		const gridTrack = grid.locator( '.estrategia-web-carousel__track' );
		const gridHandle = await gridTrack.elementHandle();
		await page.waitForFunction( ( el ) => el.closest( '.estrategia-web-carousel' ).classList.contains( 'is-ready' ), gridHandle );
		const rowsOf = () =>
			page.evaluate( ( el ) => {
				const items = ( window.__allSlides || window.__slides )( el );
				const top = Math.min( ...items.map( ( x ) => x.getBoundingClientRect().top ) );
				return items.map( ( x ) => ( x.getBoundingClientRect().top < top + 2 ? 0 : 1 ) ).join( '' );
			}, gridHandle );
		assert.equal( await rowsOf(), '000111', 'the test grid does not come out as two rows of three' );
		// The first three are on top: the position is measured on them (n = 3).
		await page.evaluate( () => {
			const all = window.__slides;
			window.__allSlides = all;
			window.__slides = ( el ) => ( el.closest( '.rejilla-prueba' ) ? all( el ).slice( 0, 3 ) : all( el ) );
		} );
		const gridNext = grid.getByRole( 'button', { name: 'Diapositiva siguiente' } );
		const gridPrev = grid.getByRole( 'button', { name: 'Diapositiva anterior' } );
		for ( const [ button, expected ] of [ [ gridNext, 1 ], [ gridNext, 2 ], [ gridNext, 0 ], [ gridPrev, 2 ], [ gridPrev, 1 ] ] ) {
			await button.click();
			await settledAt( page, gridHandle, expected, 3, `grid, towards column ${ expected + 1 }` );
			assert.equal( await rowsOf(), '000111', `grid: some card has changed row (column ${ expected + 1 })` );
		}

		// 7. A single slide: no controls and the order is not touched.
		const single = page.getByRole( 'region', { name: 'Carrusel de una', exact: true } );
		await single.scrollIntoViewIfNeeded();
		assert.ok( await single.evaluate( ( el ) => el.classList.contains( 'is-static' ) ), 'with one slide the carousel does not stay static' );
		assert.equal( await single.locator( '.estrategia-web-carousel__track > [style*="order"]' ).count(), 0, 'with one slide there is nothing to reorder' );
		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );

		// 5. Mobile with a finger: on the first one, dragging right shows the last one.
		const phone = await newPage( { viewport: { width: 402, height: 860 }, isMobile: true, hasTouch: true } );
		await phone.addInitScript( probes );
		await phone.goto( URL, { waitUntil: 'networkidle' } );
		for ( const [ name, n ] of [ [ 'Carrusel en bucle', 5 ], [ 'Carrusel en bucle con consulta', 6 ] ] ) {
			const track = phone.getByRole( 'region', { name, exact: true } ).locator( '.estrategia-web-carousel__track' );
			await track.scrollIntoViewIfNeeded();
			const handle = await track.elementHandle();
			await phone.waitForFunction( ( el ) => el.closest( '.estrategia-web-carousel' ).classList.contains( 'is-ready' ), handle );
			await settledAt( phone, handle, 0, n, `${ name } on mobile, on load` );
			const box = await track.boundingBox();
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
			await swipe( 260 );
			await settledAt( phone, handle, n - 1, n, `${ name } on mobile, finger right on the first` );
			// And again: there are still slides to the left.
			await swipe( 260 );
			await settledAt( phone, handle, n - 2, n, `${ name } on mobile, second drag` );
			// And forwards, going from the last one to the first.
			for ( const expected of [ n - 1, 0, 1 ] ) {
				await swipe( -260 );
				await settledAt( phone, handle, expected, n, `${ name } on mobile, finger left towards ${ expected + 1 }` );
			}
		}
		assert.deepEqual( phone.errors, [], phone.errors.join( '\n' ) );

		// 6. "Reduce motion": it wraps around the same, without animation.
		const calm = await newPage( { viewport: { width: 1200, height: 900 }, reducedMotion: 'reduce' } );
		await calm.addInitScript( probes );
		await calm.goto( URL, { waitUntil: 'networkidle' } );
		const carousel = calm.getByRole( 'region', { name: 'Carrusel en bucle', exact: true } );
		await carousel.scrollIntoViewIfNeeded();
		const handle = await carousel.locator( '.estrategia-web-carousel__track' ).elementHandle();
		await calm.waitForFunction( ( el ) => el.closest( '.estrategia-web-carousel' ).classList.contains( 'is-ready' ), handle );
		await carousel.locator( '.estrategia-web-carousel__dot' ).nth( 4 ).click();
		await settledAt( calm, handle, 4, 5, 'with "reduce motion", dot 5' );
		await carousel.getByRole( 'button', { name: 'Diapositiva siguiente' } ).click();
		await settledAt( calm, handle, 0, 5, 'with "reduce motion", "next" on the last' );
		assert.deepEqual( calm.errors, [], calm.errors.join( '\n' ) );
	} )
);
