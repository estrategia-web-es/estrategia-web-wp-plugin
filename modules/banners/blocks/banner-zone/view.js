/**
 * estrategia-web/banner-zone — behaviour (native ES module, no imports).
 *
 * Three jobs, all in the browser because the page is cached (render.php):
 * 1. Dates: hides banners outside their data-start / data-end (unix seconds).
 * 2. Rotation: if the zone has data-count > 0 and more banners in date than
 *    that, picks data-count of them by data-weight (Rules::pick does the same
 *    on the server) and hides the rest.
 * 3. Counting: a view when at least half the banner is on screen, once per
 *    page; a click when its link is followed. They are sent in batches with
 *    sendBeacon to data-endpoint (class-rest.php), which survives leaving
 *    the page.
 *
 * How to maintain: after changing it, `node tools/minify.mjs` (WordPress
 * serves view.min.js; tests/minified.test.mjs fails if it is stale).
 */

const now = Date.now() / 1000;
const pending = new Map(); // endpoint → Set of ids seen.
const counted = new Set();

function flush() {
	for ( const [ endpoint, ids ] of pending ) {
		if ( ids.size ) {
			send( endpoint, { views: [ ...ids ] } );
			ids.clear();
		}
	}
}

function send( endpoint, body ) {
	const blob = new Blob( [ JSON.stringify( body ) ], { type: 'application/json' } );
	if ( ! navigator.sendBeacon || ! navigator.sendBeacon( endpoint, blob ) ) {
		fetch( endpoint, { method: 'POST', body: blob, keepalive: true, headers: { 'Content-Type': 'application/json' } } ).catch( () => {} );
	}
}

function pick( banners, count ) {
	const pool = [ ...banners ];
	const chosen = [];
	while ( chosen.length < count && pool.length ) {
		const total = pool.reduce( ( sum, b ) => sum + Math.max( 1, Number( b.dataset.weight ) || 1 ), 0 );
		let n = Math.random() * total;
		const i = pool.findIndex( ( b ) => ( n -= Math.max( 1, Number( b.dataset.weight ) || 1 ) ) < 0 );
		chosen.push( pool.splice( i < 0 ? 0 : i, 1 )[ 0 ] );
	}
	return chosen;
}

const observer =
	'IntersectionObserver' in window
		? new IntersectionObserver(
				( entries ) => {
					for ( const entry of entries ) {
						if ( ! entry.isIntersecting ) {
							continue;
						}
						const banner = entry.target;
						observer.unobserve( banner );
						if ( counted.has( banner.dataset.id ) ) {
							continue;
						}
						counted.add( banner.dataset.id );
						const endpoint = banner.closest( '[data-endpoint]' ).dataset.endpoint;
						if ( ! pending.has( endpoint ) ) {
							pending.set( endpoint, new Set() );
						}
						pending.get( endpoint ).add( Number( banner.dataset.id ) );
					}
				},
				{ threshold: 0.5 }
		  )
		: null;

for ( const zone of document.querySelectorAll( '.estrategia-web-banner-zone' ) ) {
	const all = [ ...zone.querySelectorAll( ':scope > .estrategia-web-banner' ) ];
	const inDate = all.filter( ( b ) => {
		const start = Number( b.dataset.start ) || 0;
		const end = Number( b.dataset.end ) || 0;
		return ( ! start || start <= now ) && ( ! end || now <= end );
	} );
	const count = Number( zone.dataset.count ) || 0;
	const shown = count > 0 && inDate.length > count ? pick( inDate, count ) : inDate;
	for ( const banner of all ) {
		banner.hidden = ! shown.includes( banner );
	}
	zone.hidden = ! shown.length;
	for ( const banner of shown ) {
		observer?.observe( banner );
		banner.querySelector( 'a[href]' )?.addEventListener( 'click', () => send( zone.dataset.endpoint, { click: Number( banner.dataset.id ) } ) );
	}
}

setInterval( flush, 5000 );
document.addEventListener( 'visibilitychange', () => document.visibilityState === 'hidden' && flush() );
window.addEventListener( 'pagehide', flush );
