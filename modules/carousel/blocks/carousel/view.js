/**
 * estrategia-web/carousel — behaviour (Interactivity API, native ES module).
 *
 * What it does: moves the track one slide with the arrows, the keyboard or
 * the dots; without looping, flags start/end to disable arrows; with
 * looping (loop attribute, default), INFINITE (0.6.0); optional autoplay.
 * The module documentation is in the header of render.php.
 *
 * Infinite, in short (details in render.php): there are no clones. Each
 * slide gets an `order` that ROTATES the row without touching the DOM, so
 * there are always slides on both sides of the visible one. When the track
 * stops (scrollend) it rotates again around the slide at the edge and
 * scrollLeft is corrected in the same frame: the slide does not move on
 * screen and the jump is invisible.
 *
 * What rotates are UNITS: in a single row, each slide; in a multi-row grid
 * filled by rows (Lavandería's two-row clients strip), each COLUMN, and every
 * row rotates alike. A layout that is neither is not rotated: it wraps
 * around by rewinding, like 0.5.0.
 *
 * How to maintain:
 * - WordPress does NOT serve this file: it serves view.min.js (1.1.x, ~8 KB
 *   instead of ~25). After editing here, run `node tools/minify.mjs` in the
 *   same commit; tests/minified.test.mjs (level 1) fails if you forget.
 * - If something new is imported from WordPress, add it to DEPS in
 *   tools/minify.mjs (it generates view.min.asset.php).
 * - The trap: handlers that call event.preventDefault() are wrapped in
 *   withSyncEvent(). Without it, WordPress runs them deferred and
 *   preventDefault arrives too late: the arrow key moves the track AND also
 *   scrolls the page.
 * - Another, from the infinite mode: EVERY scroll that corrects the rotation
 *   uses behavior 'instant'. The track has scroll-behavior: smooth in CSS,
 *   and a bare scrollLeft = x would animate: the track would be seen running
 *   backwards, which is exactly the rewind the infinite mode removes.
 * - And another: correcting scrollLeft fires another scrollend. settle() is
 *   idempotent (if the rotation is already right it touches nothing), and
 *   while our own animation is heading to a slide it does not rotate until
 *   it arrives.
 * - And the grid one (measured on Lavandería on 03-10-2026): rotating `order`
 *   slide by slide in a two-row grid moves cards from one row to another.
 *   That is why rows are measured with the natural order (rows()) and the
 *   rotation is by columns.
 *
 * Every step SLIDES (0.7.2; David: "the carousel needs an animation, it
 * jumps", measured on 06-10-2026 on Lavandería's home page):
 * - With only ONE extra (3 photos and 2.4 in view) no rotation lets a whole
 *   slide scroll: the row is not long enough. Up to 0.7.1 it rotated and set
 *   scrollLeft to 0 at once: a jump in one frame. Now glide(): it rotates,
 *   measures where each slide ended up and animates it from where it was
 *   (transform with the Web Animations API). The one that wraps around enters
 *   from the other side and, meanwhile, a COPY of it (ghostOf) leaves on this
 *   side: without it the gap it leaves looks empty. The copy is inert,
 *   aria-hidden, without ids, with its computed styles inline (outside the
 *   track the child's :nth-child rules do not reach it) and is removed at the
 *   end. Clicking while it animates does not cut it: the step is queued
 *   (state.queue).
 * - Rapid clicks on a track that DOES scroll: the rotation is kept if it
 *   already allows the trip, so the browser redirects its animation without
 *   stopping it; and while our own animation runs the track has no
 *   scroll-snap (hold/release): with mandatory snap, the instant scrollLeft
 *   correction landed on the nearest snap point and the track jumped.
 * - The trap: an inline scroll-snap-type left behind leaves the track with
 *   no snapping for the finger. It is removed on arrival (settle), on touch
 *   or wheel (take) and at the end of glide().
 */
import { store, getContext, getElement, withSyncEvent } from '@wordpress/interactivity';

const reducedMotion = () => window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
const behavior = () => ( reducedMotion() ? 'instant' : 'smooth' );

const rootOf = ( element ) => element.closest( '.estrategia-web-carousel' );
const trackOf = ( element ) => rootOf( element ).querySelector( '.estrategia-web-carousel__track' );

/**
 * Slides of a track in LOGICAL order (DOM order, never changed): its direct
 * children, except a core/query, which contributes each <li> of its
 * post-template (see render.php, 0.2.0).
 */
const slidesOf = ( track ) =>
	Array.from( track.children ).flatMap( ( child ) =>
		child.classList.contains( 'wp-block-query' )
			? Array.from( child.querySelectorAll( ':scope > .wp-block-post-template > li' ) )
			: [ child ]
	);

/** Offsets of groups of elements relative to the leftmost one. */
const offsetsOf = ( groups ) => {
	const lefts = groups.map( ( group ) => Math.min( ...group.map( ( el ) => el.offsetLeft ) ) );
	const first = Math.min( ...lefts );
	return lefts.map( ( left ) => left - first );
};

const slideOffsets = ( track ) => offsetsOf( slidesOf( track ).map( ( slide ) => [ slide ] ) );

const maxScroll = ( track ) => track.scrollWidth - track.clientWidth;

/** Index of the offset nearest to the scroll position. */
const nearestIndex = ( track, offsets = slideOffsets( track ) ) => {
	let best = 0;
	offsets.forEach( ( offset, i ) => {
		if ( Math.abs( offset - track.scrollLeft ) < Math.abs( offsets[ best ] - track.scrollLeft ) ) {
			best = i;
		}
	} );
	return best;
};

/* ---------------------------------------------------------------------------
 * Without loop (as in 0.1.0 – 0.4.0): a row with a start and an end. Also the
 * fallback of the loop for layouts that cannot rotate (rewind, as in 0.5.0).
 * ------------------------------------------------------------------------- */

const scrollToIndex = ( track, index ) => {
	const offsets = slideOffsets( track );
	if ( ! offsets.length ) {
		return;
	}
	const target = Math.max( 0, Math.min( index, offsets.length - 1 ) );
	track.scrollTo( { left: offsets[ target ], behavior: behavior() } );
};

const stepLinear = ( track, direction ) => scrollToIndex( track, nearestIndex( track ) + direction );

const stepRewind = ( track, direction ) => {
	if ( direction > 0 && track.scrollLeft + track.clientWidth >= track.scrollWidth - 1 ) {
		scrollToIndex( track, 0 );
	} else if ( direction < 0 && track.scrollLeft <= 1 ) {
		scrollToIndex( track, slidesOf( track ).length - 1 );
	} else {
		stepLinear( track, direction );
	}
};

/* ---------------------------------------------------------------------------
 * Infinite (0.6.0). Per-track state lives here, not in the context: it holds
 * numbers about the layout that no directive reads.
 * ------------------------------------------------------------------------- */

const ring = new WeakMap();
const ringOf = ( track ) => {
	if ( ! ring.has( track ) ) {
		ring.set( track, { start: 0, cols: 0, moving: false, target: 0, touching: false, gliding: false, queue: [] } );
	}
	return ring.get( track );
};

/** Our own animations run without scroll-snap (see the header, 0.7.2). */
const hold = ( track ) => track.style.setProperty( 'scroll-snap-type', 'none' );
const release = ( track ) => track.style.removeProperty( 'scroll-snap-type' );

/**
 * Measures the rows with the NATURAL order (inline orders removed and put
 * back in the same task: nothing is painted in between). Sets state.cols:
 * the number of units, or 0 if the layout cannot rotate (rows of different
 * lengths, or not filled row by row). A slide starts a new row when it
 * starts below the bottom of the current one, so slides of different
 * heights aligned in one row are still one row.
 */
const measure = ( track ) => {
	const state = ringOf( track );
	const slides = slidesOf( track );
	const saved = slides.map( ( slide ) => slide.style.order );
	slides.forEach( ( slide ) => ( slide.style.order = '' ) );
	const rowOf = [];
	let rows = 0;
	let bottom = -Infinity;
	slides.forEach( ( slide ) => {
		if ( slide.offsetTop >= bottom - 1 ) {
			rows++;
			bottom = -Infinity;
		}
		bottom = Math.max( bottom, slide.offsetTop + slide.offsetHeight );
		rowOf.push( rows - 1 );
	} );
	slides.forEach( ( slide, i ) => ( slide.style.order = saved[ i ] ) );
	const cols = slides.length / rows;
	const regular = Number.isInteger( cols ) && rowOf.every( ( row, i ) => row === Math.floor( i / cols ) );
	state.cols = regular ? cols : 0;
	return state.cols;
};

/** Units: one per column (one per slide in a single row), its slides inside. */
const unitsOf = ( track ) => {
	const slides = slidesOf( track );
	const cols = ringOf( track ).cols || slides.length;
	return Array.from( { length: cols }, ( _, c ) => slides.filter( ( __, i ) => i % cols === c ) );
};

/** Rotates every row so that unit `start` is visually first. */
const arrange = ( track, start ) => {
	const state = ringOf( track );
	const slides = slidesOf( track );
	const cols = state.cols || slides.length;
	state.start = start;
	slides.forEach( ( slide, i ) => {
		const row = Math.floor( i / cols );
		slide.style.order = String( row * cols + ( ( ( i % cols ) - start + cols ) % cols ) );
	} );
};

/**
 * Where unit `a` would start if the row were rotated so that it sits at
 * visual position `lead`, and where position `lead + d` would start. Pure
 * arithmetic on the widths (they do not depend on the order), so candidates
 * are compared without touching the layout.
 */
const layoutFor = ( widths, gap, a, lead, d ) => {
	const n = widths.length;
	const start = ( a - lead + n * 2 ) % n;
	const at = ( pos ) => {
		let sum = 0;
		for ( let q = 0; q < pos; q++ ) {
			sum += widths[ ( start + q ) % n ] + gap;
		}
		return sum;
	};
	return { start, from: at( lead ), to: at( lead + d ) };
};

/**
 * Moves the visible window from unit `a` by `d` units (negative: to the
 * left). First rotates the row so that BOTH ends of the trip exist and can
 * be scrolled to, keeping `a` where it is on screen; then scrolls. Returns
 * false if no rotation allows it.
 */
const travel = ( track, a, d, animate ) => {
	const state = ringOf( track );
	const units = unitsOf( track );
	const n = units.length;
	const widths = units.map( ( unit ) => Math.max( ...unit.map( ( el ) => el.offsetWidth ) ) );
	const gap = parseFloat( window.getComputedStyle( track ).columnGap ) || 0;
	const limit = maxScroll( track ) + 1;
	const delta = track.scrollLeft - offsetsOf( units )[ a ];

	// Lead: how many units to the left of `a`. Preferred: half of the ones
	// that do not fit on screen, so there is room to both sides.
	let visible = 0;
	for ( let used = 0; visible < n; visible++ ) {
		used += widths[ ( a + visible ) % n ] + ( visible ? gap : 0 );
		if ( used > track.clientWidth + 1 ) {
			break;
		}
	}
	const ideal = Math.max( 0, Math.floor( ( n - visible ) / 2 ) );

	// A trip the current rotation already allows keeps it: re-rotating in
	// the middle of an animation stops it dead and starts it again (0.7.2).
	const current = ( a - state.start + n * 2 ) % n;
	let best = null;
	for ( let lead = 0; lead < n; lead++ ) {
		if ( lead + d < 0 || lead + d > n - 1 ) {
			continue;
		}
		const plan = layoutFor( widths, gap, a, lead, d );
		if ( plan.from > limit || plan.to > limit ) {
			continue;
		}
		if ( d !== 0 && lead === current ) {
			best = { ...plan, lead };
			break;
		}
		if ( ! best || Math.abs( lead - ideal ) < Math.abs( best.lead - ideal ) ) {
			best = { ...plan, lead };
		}
	}
	if ( ! best ) {
		return false;
	}
	if ( animate && ! reducedMotion() ) {
		hold( track );
	}
	// Rotation 0 is the DOM order itself: a row that never rotates keeps no
	// inline `order` at all.
	if ( best.start !== state.start ) {
		arrange( track, best.start );
		const keep = offsetsOf( units )[ a ] + delta;
		if ( Math.abs( keep - track.scrollLeft ) > 0.5 ) {
			track.scrollTo( { left: keep, behavior: 'instant' } );
		}
	}
	if ( d !== 0 || animate ) {
		const target = ( a + d + n * 2 ) % n;
		const to = offsetsOf( units )[ target ];
		if ( Math.abs( to - track.scrollLeft ) > 0.5 ) {
			state.moving = true;
			state.target = target;
			track.scrollTo( { left: to, behavior: behavior() } );
		} else if ( ! state.moving ) {
			release( track );
		}
	}
	return true;
};

/**
 * A visual copy of a slide for glide(): inert, without ids, its computed
 * styles inline (outside the track the child's selectors do not reach it).
 */
const ghostOf = ( slide ) => {
	const copy = slide.cloneNode( true );
	const paint = ( from, to ) => {
		const style = window.getComputedStyle( from );
		for ( let i = 0; i < style.length; i++ ) {
			const name = style[ i ];
			if ( ! name.startsWith( 'animation' ) && ! name.startsWith( 'transition' ) ) {
				to.style.setProperty( name, style.getPropertyValue( name ) );
			}
		}
		to.removeAttribute( 'id' );
		if ( 'IMG' === to.tagName ) {
			to.removeAttribute( 'srcset' );
			to.removeAttribute( 'sizes' );
			to.setAttribute( 'loading', 'eager' );
			to.src = from.currentSrc || from.src;
		}
		Array.from( from.children ).forEach( ( child, i ) => to.children[ i ] && paint( child, to.children[ i ] ) );
	};
	paint( slide, copy );
	return copy;
};

const GLIDE = { duration: 450, easing: 'cubic-bezier(0.25, 0.1, 0.25, 1)' };

/**
 * Moves from unit `a` to unit `target` (`d` units, negative: to the left)
 * when no rotation lets the track scroll there (one slide too few). Rotates
 * and resets the scroll, then animates every slide from where it WAS; the
 * slides that go round the ring enter from the other side while a ghost of
 * them leaves on this one. Without animation (reduced motion) only the first
 * part: the new slide is there at once.
 */
const glide = ( track, a, target, d ) => {
	const state = ringOf( track );
	const slides = slidesOf( track );
	const units = unitsOf( track );
	const before = slides.map( ( slide ) => slide.getBoundingClientRect() );
	state.moving = false;
	arrange( track, target );
	track.scrollTo( { left: 0, behavior: 'instant' } );
	// The dots follow the slide at the edge, also when scrollLeft was already 0.
	track.dispatchEvent( new window.Event( 'scroll' ) );
	if ( reducedMotion() || ! d || 'function' !== typeof track.animate ) {
		return;
	}
	const after = slides.map( ( slide ) => slide.getBoundingClientRect() );
	// A slide that keeps its place in the ring: the target going forwards, the
	// one we leave going backwards.
	const ref = slides.indexOf( units[ d > 0 ? target : a ][ 0 ] );
	const shift = before[ ref ].left - after[ ref ].left;
	if ( Math.abs( shift ) < 1 ) {
		return;
	}
	const box = track.getBoundingClientRect();
	const left = box.left + track.clientLeft;
	const top = box.top + track.clientTop;
	const root = rootOf( track );
	const rootBox = root.getBoundingClientRect();
	const layer = document.createElement( 'div' );
	layer.className = 'estrategia-web-carousel__ghosts';
	layer.setAttribute( 'aria-hidden', 'true' );
	layer.inert = true;
	Object.assign( layer.style, {
		position: 'absolute',
		left: `${ left - rootBox.left - root.clientLeft }px`,
		top: `${ top - rootBox.top - root.clientTop }px`,
		width: `${ track.clientWidth }px`,
		height: `${ track.clientHeight }px`,
		overflow: 'hidden',
		pointerEvents: 'none',
	} );
	slides.forEach( ( slide, i ) => {
		const was = before[ i ];
		const wentRound = Math.abs( was.left - after[ i ].left - shift ) > 1;
		const seen = was.right > left && was.left < left + track.clientWidth;
		if ( wentRound && seen ) {
			const ghost = ghostOf( slide );
			Object.assign( ghost.style, {
				position: 'absolute',
				left: `${ was.left - left }px`,
				top: `${ was.top - top }px`,
				width: `${ was.width }px`,
				height: `${ was.height }px`,
				margin: '0',
				boxSizing: 'border-box',
				transform: 'none',
			} );
			layer.append( ghost );
		}
	} );
	root.append( layer );
	hold( track );
	state.gliding = true;
	const moves = slides.map( ( slide ) => slide.animate( [ { transform: `translateX(${ shift }px)` }, { transform: 'none' } ], GLIDE ) );
	Array.from( layer.children ).forEach( ( ghost ) =>
		moves.push( ghost.animate( [ { transform: 'none' }, { transform: `translateX(${ -shift }px)` } ], GLIDE ) )
	);
	Promise.all( moves.map( ( move ) => move.finished ) )
		.catch( () => {} )
		.then( () => {
			layer.remove();
			release( track );
			state.gliding = false;
			while ( ! state.gliding && state.queue.length ) {
				state.queue.shift()();
			}
		} );
};

/** While glide() animates, a new step waits its turn (a few at most). */
const later = ( track, fn ) => {
	const state = ringOf( track );
	if ( ! state.gliding ) {
		return false;
	}
	if ( state.queue.length < 4 ) {
		state.queue.push( fn );
	}
	return true;
};

/** Whether the infinite mode can rotate this track (see measure()). */
const rotates = ( track ) => ringOf( track ).cols > 1;

const nearestUnit = ( track ) => nearestIndex( track, offsetsOf( unitsOf( track ) ) );

/** Re-centres the row around the unit at the edge, invisibly. */
const settle = ( track ) => {
	const state = ringOf( track );
	if ( state.touching || state.gliding || ! rotates( track ) || maxScroll( track ) <= 1 ) {
		return;
	}
	if ( state.moving ) {
		// Our own animation is still on its way: wait until it arrives.
		if ( Math.abs( offsetsOf( unitsOf( track ) )[ state.target ] - track.scrollLeft ) > 1 ) {
			return;
		}
		state.moving = false;
	}
	release( track );
	travel( track, nearestUnit( track ), 0, false );
};

/** Goes to unit `target`, by the shortest way round (or towards `dir`). */
const goInfinite = ( track, target, dir = 0 ) => {
	const state = ringOf( track );
	if ( ! rotates( track ) ) {
		scrollToIndex( track, target );
		return;
	}
	if ( later( track, () => goInfinite( track, target, dir ) ) ) {
		return;
	}
	const n = state.cols;
	const a = nearestUnit( track );
	const forward = ( target - a + n ) % n;
	let d;
	if ( dir > 0 ) {
		d = forward;
	} else if ( dir < 0 ) {
		d = forward ? forward - n : 0;
	} else {
		d = forward <= n / 2 ? forward : forward - n;
	}
	if ( ! travel( track, a, d, true ) ) {
		// No rotation fits both ends (one slide too few): animate it apart.
		release( track );
		glide( track, a, target, d );
	}
};

const stepInfinite = ( track, direction ) => {
	const state = ringOf( track );
	if ( ! rotates( track ) ) {
		stepRewind( track, direction );
		return;
	}
	if ( later( track, () => stepInfinite( track, direction ) ) ) {
		return;
	}
	const n = state.cols;
	const from = state.moving ? state.target : nearestUnit( track );
	goInfinite( track, ( from + direction + n ) % n, direction );
};

/* ------------------------------------------------------------------------- */

const step = ( context, track, direction ) =>
	context.loop ? stepInfinite( track, direction ) : stepLinear( track, direction );

const goTo = ( context, track, index ) => ( context.loop ? goInfinite( track, index ) : scrollToIndex( track, index ) );

const updateEdges = ( context, track ) => {
	context.atStart = track.scrollLeft <= 1;
	context.atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
	if ( context.loop && rotates( track ) ) {
		// Infinite: the active dot is the slide (unit) at the left edge.
		context.current = nearestUnit( track );
	} else {
		context.current = context.atEnd ? context.count - 1 : nearestIndex( track );
	}
};

const { state } = store( 'estrategia-web/carousel', {
	state: {
		get isStatic() {
			const context = getContext();
			return context.ready && context.atStart && context.atEnd;
		},
		get isCurrentDot() {
			const context = getContext();
			return context.current === context.index ? 'true' : 'false';
		},
		get toggleLabel() {
			const context = getContext();
			return context.playing ? context.labelPause : context.labelPlay;
		},
	},
	actions: {
		prev() {
			step( getContext(), trackOf( getElement().ref ), -1 );
		},
		next() {
			step( getContext(), trackOf( getElement().ref ), 1 );
		},
		goTo() {
			const context = getContext();
			goTo( context, trackOf( getElement().ref ), context.index );
		},
		onKeydown: withSyncEvent( ( event ) => {
			const context = getContext();
			const track = event.currentTarget;
			const moves = { ArrowLeft: -1, ArrowRight: 1 };
			if ( event.key in moves ) {
				event.preventDefault();
				step( context, track, moves[ event.key ] );
			} else if ( 'Home' === event.key ) {
				event.preventDefault();
				goTo( context, track, 0 );
			} else if ( 'End' === event.key ) {
				event.preventDefault();
				const units = context.loop && rotates( track ) ? ringOf( track ).cols : slidesOf( track ).length;
				goTo( context, track, units - 1 );
			}
		} ),
		toggleAutoplay() {
			const context = getContext();
			context.playing = ! context.playing;
		},
		hoverIn() {
			getContext().hover = true;
		},
		hoverOut() {
			getContext().hover = false;
		},
		focusIn() {
			getContext().focused = true;
		},
		focusOut( event ) {
			const root = rootOf( getElement().ref );
			getContext().focused = root.contains( event.relatedTarget );
		},
	},
	callbacks: {
		init() {
			const context = getContext();
			const track = trackOf( getElement().ref );
			const cleanups = [];

			// Infinite only with something to scroll: one slide, or all of them
			// on screen, is a still row (is-static), and nothing is touched
			// (settle() checks it every time: a hidden carousel, in a tab, has
			// nothing to scroll until it shows up).
			if ( context.loop && slidesOf( track ).length > 1 ) {
				measure( track );
				settle( track );
				const ringState = ringOf( track );
				const onSettle = () => settle( track );
				let timer = 0;
				// Safari before 26 has no scrollend: the same after a quiet pause.
				const onScroll = () => {
					window.clearTimeout( timer );
					timer = window.setTimeout( onSettle, 120 );
				};
				const usesScrollEnd = 'onscrollend' in window;
				track.addEventListener( usesScrollEnd ? 'scrollend' : 'scroll', usesScrollEnd ? onSettle : onScroll );
				// A finger on the track, or the wheel, takes over from our animation.
				const take = () => {
					ringState.moving = false;
					release( track );
				};
				const touchStart = () => {
					ringState.touching = true;
					take();
				};
				const touchEnd = () => {
					ringState.touching = false;
					if ( ! usesScrollEnd ) {
						onScroll();
					}
				};
				track.addEventListener( 'touchstart', touchStart, { passive: true } );
				track.addEventListener( 'touchend', touchEnd, { passive: true } );
				track.addEventListener( 'touchcancel', touchEnd, { passive: true } );
				track.addEventListener( 'wheel', take, { passive: true } );
				// New width (rotating the phone, a breakpoint): rows and rotation
				// are measured again. The observer also fires once right after
				// observe() with the width that init just measured: that first
				// call only records it (1.1.x). It used to measure everything
				// twice, and measure() clears and restores `order`, which forces
				// a layout (trace of Lavandería's home on a phone, 14-10-2026).
				// Width read from the entry, never from the layout.
				let width = null;
				const resize = new window.ResizeObserver( ( entries ) => {
					const now = entries[ entries.length - 1 ].contentRect.width;
					const first = null === width;
					const same = ! first && Math.abs( now - width ) < 0.5;
					width = now;
					if ( first || same ) {
						return;
					}
					if ( ! ringState.moving && ! ringState.gliding ) {
						measure( track );
						settle( track );
					}
				} );
				resize.observe( track );
				cleanups.push( () => {
					track.removeEventListener( 'scrollend', onSettle );
					track.removeEventListener( 'scroll', onScroll );
					track.removeEventListener( 'touchstart', touchStart );
					track.removeEventListener( 'touchend', touchEnd );
					track.removeEventListener( 'touchcancel', touchEnd );
					track.removeEventListener( 'wheel', take );
					resize.disconnect();
					window.clearTimeout( timer );
				} );
			}

			updateEdges( context, track );
			context.ready = true;

			if ( context.autoplay && ! reducedMotion() ) {
				context.playing = true;
				const timer = window.setInterval( () => {
					if ( ! context.playing || context.hover || context.focused ) {
						return;
					}
					if ( ! context.loop && context.atEnd ) {
						scrollToIndex( track, 0 );
					} else {
						step( context, track, 1 );
					}
				}, context.interval );
				cleanups.push( () => window.clearInterval( timer ) );
			}
			return () => cleanups.forEach( ( cleanup ) => cleanup() );
		},
		onScroll() {
			const context = getContext();
			updateEdges( context, getElement().ref );
		},
	},
} );

export { state };
