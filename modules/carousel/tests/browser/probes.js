// NIVEL: BANCO DE TRABAJO — not a test. The probes the carousel scenarios load with «initScripts» (tests/browser.mjs): they measure, in the page, which slide is SEEN and record it frame by frame.
//
// A carousel is named by its accessible name (the block's «label», its aria-label).
//
//   __track(name)            its track (.estrategia-web-carousel__track)
//   __slides(track)          the REAL slides in DOM (logical) order: the track's children, or the
//                            posts (li.wp-block-post) of a Query inside it; what the carousel adds to
//                            fill a grid is not one of them
//   __row(track)             the slides of the first row: in the two-row test grids
//                            (.rejilla-prueba, 3 columns; .rejilla-impar, 12 columns) the first ones
//   __pos(track)             the continuous position: the index of the slide at the left edge plus the
//                            fraction already scrolled past it, measured on screen. DOM order is the
//                            logical order (the carousel never reorders the DOM), so it says which slide
//                            is SEEN, whatever the technique
//   __settled(name, i, n)    true when the carousel is at slide i (on a ring of n) AND still: the same
//                            position three frames in a row. A waitForExpression tries it every frame
//   __rec(name)              starts recording __pos every frame
//   __end(name, label, n, distance, smooth, to, burst?)
//                            stops it and keeps the trip in window.__trips with what its check needs
//                            (samples, ghosts left, number of slides, the dots' aria-current, the rects
//                            of slides 4 and 0); smooth null = only «never goes back» is checked
//   __layout(name, columns?) { rows: "000111…", misplaced } of the real slides (misplaced: bottom cards
//                            not under their column, when columns is given)
//   __keep(key, value)       window.__kept[key] = value (read by a capture's values)
//
// Where it comes from: the probes of carousel-infinite.test.mjs and any-count.mjs (28-10-2026),
// which ran the same functions with page.evaluate between Playwright calls.
// How to maintain it: a new carousel measurement goes here, used from the scenario's «run» and
// «values». The trap: it runs before the page's scripts (an init script), so it only DEFINES
// functions; nothing here may look at the DOM until called.
window.__track = ( name ) => [ ...document.querySelectorAll( '.estrategia-web-carousel' ) ].find( ( c ) => c.getAttribute( 'aria-label' ) === name )?.querySelector( '.estrategia-web-carousel__track' ) || null;
window.__slides = ( track ) =>
	[ ...track.children ].flatMap( ( c ) => ( c.classList.contains( 'wp-block-query' ) ? [ ...c.querySelectorAll( ':scope > .wp-block-post-template > li.wp-block-post' ) ] : [ c ] ) );
window.__row = ( track ) => {
	const all = window.__slides( track );
	if ( track.closest( '.rejilla-prueba' ) ) {
		return all.slice( 0, 3 );
	}
	return track.closest( '.rejilla-impar' ) ? all.slice( 0, 12 ) : all;
};
window.__pos = ( track ) => {
	const edge = track.getBoundingClientRect().left;
	const gap = parseFloat( getComputedStyle( track ).columnGap ) || 0;
	let best = null;
	window.__row( track ).forEach( ( slide, i ) => {
		const r = slide.getBoundingClientRect();
		// The slide that covers the edge, or (in a gap) the next one.
		if ( r.right + gap > edge + 0.5 && ( ! best || r.left < best.r.left ) ) {
			best = { i, r };
		}
	} );
	return best.i + ( edge - best.r.left ) / ( best.r.width + gap );
};
window.__settled = ( name, i, n ) => {
	const p = window.__pos( window.__track( name ) );
	const d = Math.abs( ( ( ( ( p - i ) % n ) + n + n / 2 ) % n ) - n / 2 );
	const s = ( ( window.__still ||= {} )[ name ] ||= {} );
	const still = s.last === p;
	s.last = p;
	s.count = d < 0.02 && still ? ( s.count || 0 ) + 1 : 0;
	return s.count >= 3;
};
window.__rec = ( name ) => {
	const track = window.__track( name );
	cancelAnimationFrame( window.__frame );
	window.__samples = [];
	const tick = () => {
		window.__samples.push( window.__pos( track ) );
		window.__frame = requestAnimationFrame( tick );
	};
	tick();
};
window.__end = ( name, label, n, distance, smooth, to, burst = false ) => {
	cancelAnimationFrame( window.__frame );
	const track = window.__track( name );
	const carousel = track.closest( '.estrategia-web-carousel' );
	const slides = window.__slides( track );
	const rect = ( el ) => el && el.getBoundingClientRect().toJSON();
	( window.__trips ||= [] ).push( {
		name,
		label,
		n,
		distance,
		smooth,
		to,
		burst,
		samples: window.__samples,
		ghosts: carousel.querySelectorAll( '.estrategia-web-carousel__ghosts' ).length,
		slides: slides.length,
		dots: [ ...carousel.querySelectorAll( '.estrategia-web-carousel__dot' ) ].map( ( d ) => d.getAttribute( 'aria-current' ) ),
		peek: [ rect( slides[ 4 ] ), rect( slides[ 0 ] ) ],
	} );
	return true;
};
window.__layout = ( name, columns ) => {
	const items = window.__slides( window.__track( name ) );
	const top = Math.min( ...items.map( ( x ) => x.getBoundingClientRect().top ) );
	const rows = items.map( ( x ) => ( x.getBoundingClientRect().top < top + 2 ? 0 : 1 ) ).join( '' );
	const misplaced = columns
		? items
				.slice( columns )
				.map( ( x, c ) => Math.abs( x.getBoundingClientRect().left - items[ c ].getBoundingClientRect().left ) )
				.filter( ( d ) => d > 1 ).length
		: null;
	return { rows, misplaced };
};
window.__keep = ( key, value ) => {
	( window.__kept ||= {} )[ key ] = value;
	return true;
};
