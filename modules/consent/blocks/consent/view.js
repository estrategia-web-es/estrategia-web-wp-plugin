/**
 * Cookie notice — behaviour (Interactivity API, store «estrategia-web/consent»).
 *
 * The module documentation (categories, API, WP Consent API, traps) is in
 * the header of includes/class-consent.php. Here, only what concerns this
 * file:
 *
 * - It came from the parent theme (armazon-wp-padre ≤ 0.6.0, blocks/consent/view.js)
 *   in estrategia-web-site 0.5.0 WITHOUT changing the contract: same store,
 *   same cookie, same format, same API and same event. A visitor who already
 *   chose with the parent's notice does not see the notice again.
 * - The choice is stored in the technical cookie «estrategia_web_consent»
 *   with the format "<revision>.<cat~cat>.<unix>". PHP reads the same format
 *   (Consent::choice): if it changes here, it changes there.
 * - 1.0.0 (rename): if there is no new cookie and the old one
 *   («armazon_consent», state.legacyCookie) is valid for the revision, that
 *   one is taken, the new one is written (with today's date: the year of life
 *   starts counting again) and the old one is deleted. Removed in 2.0.0.
 * - Public API, for the theme, Matomo (modules/matomo), Google
 *   (modules/google-tags) and other blocks (estrategia-web/map uses it):
 *     window.estrategiaWebConsent.categories     → categories the notice asks about
 *     window.estrategiaWebConsent.has( 'maps' )  → boolean («necessary», always true)
 *     window.estrategiaWebConsent.get()          → string[] | null (null = no choice yet)
 *     window.estrategiaWebConsent.open()         → reopens the notice
 *     window.estrategiaWebConsent.grant( 'maps' ) → adds ONE category to the
 *       choice («Show map» uses it). If the site does not declare it, it does
 *       nothing. It does not close the notice if it was open: the visitor
 *       keeps choosing the rest; if they then press «Reject all», it is
 *       withdrawn.
 *     document.addEventListener( 'estrategia-web:consent', ( e ) => e.detail.accepted )
 *   The event fires on boot (if there was already a choice) and on every change.
 *     document.addEventListener( 'estrategia-web:consent-ready', ( e ) => e.detail.accepted )
 *   (0.5.0, new) fires ONCE on boot, whether there is a choice or not
 *   (accepted = null if there is none), right after creating window.estrategiaWebConsent.
 *   It is for whoever needs to learn about «no choice yet» (Matomo, trap 1).
 *   1.1.0: has() and grant() accept aliases (state.aliases: analytics →
 *   statistics, advertising/ads → marketing). get() and the event always
 *   return the real slugs.
 *   The recipe for whoever waits for consent (both paths, see the trap):
 *     const sync = ( accepted ) => { … accepted may be null … };
 *     document.addEventListener( 'estrategia-web:consent', ( e ) => sync( e.detail.accepted ) );
 *     document.addEventListener( 'estrategia-web:consent-ready', ( e ) => sync( e.detail.accepted ) );
 *     if ( window.estrategiaWebConsent ) sync( window.estrategiaWebConsent.get() );
 * - 1.1.0, REOPEN from any link: a click on a[href="#estrategia-web-consent"]
 *   or on something with the estrategia-web-consent-open class (or the
 *   data-estrategia-web-consent-open attribute) opens the panel (delegated on
 *   document, so it works for menus rendered later).
 * - 1.1.0, the DETECTOR (header of includes/class-cookie-rest.php): when the
 *   choice accepts ANYTHING, after state.detector.delay ms (so that the
 *   freshly activated scripts set their cookies) the NAMES are read from
 *   document.cookie and sent to state.detector.url. Only if the LIST OF NAMES
 *   changed since the last send or state.detector.days days have passed
 *   (localStorage «estrategia-web-cookie-scan»). No server list version is
 *   used: see why in Consent::state(). Values never leave the browser: each
 *   pair is cut at the first «=» and only the left side is kept.
 * - The trap: ES modules run deferred and in arrival order; another module
 *   (or the Matomo script) may boot BEFORE this one. Whoever checks consent
 *   must look at window.estrategiaWebConsent if it exists AND listen to the
 *   event, never just one of the two.
 */
import { store, getContext, getElement, withSyncEvent } from '@wordpress/interactivity';

let choice = null;
let opener = null;
let scanTimer = null;

const SCAN_KEY = 'estrategia-web-cookie-scan';
const OPEN_SELECTOR = 'a[href="#estrategia-web-consent"], .estrategia-web-consent-open, [data-estrategia-web-consent-open]';

const readCookie = ( name ) => {
	const match = document.cookie.split( '; ' ).find( ( row ) => row.startsWith( `${ name }=` ) );
	return match ? decodeURIComponent( match.slice( name.length + 1 ) ) : null;
};

const parseChoice = ( raw, revision, categories ) => {
	if ( ! raw ) {
		return null;
	}
	const parts = raw.split( '.' );
	if ( parts.length !== 3 || parts[ 0 ] !== revision ) {
		return null;
	}
	const accepted = parts[ 1 ] ? parts[ 1 ].split( '~' ) : [];
	return categories.filter( ( category ) => accepted.includes( category ) );
};

/** Turns <script type="text/plain" data-estrategia-web-consent="x"> into real scripts. */
const activateScripts = ( accepted ) => {
	document.querySelectorAll( 'script[type="text/plain"][data-estrategia-web-consent]:not([data-estrategia-web-activated])' ).forEach( ( placeholder ) => {
		if ( ! accepted.includes( placeholder.dataset.estrategiaWebConsent ) ) {
			return;
		}
		const script = document.createElement( 'script' );
		for ( const { name, value } of placeholder.attributes ) {
			if ( ! [ 'type', 'data-estrategia-web-consent', 'data-estrategia-web-type' ].includes( name ) ) {
				script.setAttribute( name, value );
			}
		}
		script.type = placeholder.dataset.estrategiaWebType || 'text/javascript';
		script.text = placeholder.text;
		placeholder.dataset.estrategiaWebActivated = '';
		placeholder.after( script );
	} );
};

/** Optional WP Consent API (plugin). Mapping documented in includes/class-consent.php. */
const tellWpConsentApi = ( accepted ) => {
	if ( typeof window.wp_set_consent !== 'function' ) {
		return;
	}
	window.wp_consent_type = 'optin';
	const value = ( category ) => ( accepted.includes( category ) ? 'allow' : 'deny' );
	window.wp_set_consent( 'statistics', value( 'statistics' ) );
	window.wp_set_consent( 'statistics-anonymous', value( 'statistics' ) );
	window.wp_set_consent( 'marketing', value( 'marketing' ) );
	window.wp_set_consent( 'preferences', value( 'preferences' ) );
};

/** Cookie NAMES only: everything after the first «=» is dropped here and never leaves the browser. */
const cookieNames = () =>
	[ ...new Set( document.cookie.split( ';' ).map( ( pair ) => pair.split( '=' )[ 0 ].trim() ).filter( Boolean ) ) ].sort();

/** Sends the names to the site if something changed or the period is over (see the header). */
const scanCookies = () => {
	const detector = state.detector;
	const names = cookieNames();
	if ( ! detector || ! detector.url || ! names.length ) {
		return;
	}
	const key = names.join( ',' );
	let last = null;
	try {
		last = JSON.parse( window.localStorage.getItem( SCAN_KEY ) || 'null' );
	} catch ( e ) {
		last = null;
	}
	const now = Date.now();
	if ( last && last.key === key && now - last.at < detector.days * 86400000 ) {
		return;
	}
	try {
		window.localStorage.setItem( SCAN_KEY, JSON.stringify( { key, at: now } ) );
	} catch ( e ) {
		// Without localStorage it may send on every page: the server rate-limits it.
	}
	window
		.fetch( detector.url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { names } ),
			credentials: 'omit',
			keepalive: true,
		} )
		.catch( () => {} );
};

const scheduleScan = ( accepted ) => {
	if ( ! accepted.length || ! state.detector ) {
		return;
	}
	window.clearTimeout( scanTimer );
	scanTimer = window.setTimeout( scanCookies, state.detector.delay ?? 4000 );
};

const apply = ( accepted ) => {
	activateScripts( accepted );
	tellWpConsentApi( accepted );
	document.dispatchEvent( new CustomEvent( 'estrategia-web:consent', { detail: { accepted: [ ...accepted ] } } ) );
	scheduleScan( accepted );
};

/** Saves the current choice in the technical cookie (format: see the header). */
const writeCookie = () => {
	const value = `${ state.revision }.${ choice.join( '~' ) }.${ Math.floor( Date.now() / 1000 ) }`;
	const secure = window.location.protocol === 'https:' ? '; Secure' : '';
	document.cookie = `${ state.cookieName }=${ encodeURIComponent( value ) }; Max-Age=${ state.maxAge }; Path=/; SameSite=Lax${ secure }`;
};

/** «analytics» → «statistics» (state.aliases, from Consent::ALIASES). */
const normalize = ( category ) => {
	const slug = String( category ).toLowerCase();
	return ( state.aliases || {} )[ slug ] || slug;
};

const { state, actions } = store( 'estrategia-web/consent', {
	state: {
		draft: [],
		focusRequested: false,
		get isChecked() {
			return state.draft.includes( getContext().category );
		},
	},
	actions: {
		save( accepted ) {
			const previous = choice;
			choice = state.categories.filter( ( category ) => accepted.includes( category ) );
			writeCookie();
			state.isOpen = false;
			state.showSettings = false;

			// Something already ran for a category that is now rejected: only a reload unloads it.
			const withdrawn = ( previous || [] ).filter( ( category ) => ! choice.includes( category ) );
			const ranWithdrawn = withdrawn.some( ( category ) =>
				document.querySelector( `script[data-estrategia-web-consent="${ category }"][data-estrategia-web-activated]` )
			);
			if ( ranWithdrawn ) {
				window.location.reload();
				return;
			}
			apply( choice );
			if ( opener && document.contains( opener ) ) {
				opener.focus();
			}
			opener = null;
		},
		acceptAll() {
			actions.save( state.categories );
		},
		rejectAll() {
			actions.save( [] );
		},
		toggleSettings() {
			state.showSettings = ! state.showSettings;
		},
		toggleCategory( event ) {
			const { category } = getContext();
			state.draft = event.target.checked
				? [ ...new Set( [ ...state.draft, category ] ) ]
				: state.draft.filter( ( item ) => item !== category );
		},
		saveSettings: withSyncEvent( ( event ) => {
			event.preventDefault();
			actions.save( state.draft );
		} ),
		open() {
			opener = document.activeElement;
			state.draft = choice ? [ ...choice ] : [];
			state.showSettings = true;
			state.isOpen = true;
			// Focus moves in callbacks.focusOnOpen, AFTER the notice is visible:
			// focusing it from here (even in a requestAnimationFrame) hits a still
			// hidden element and the focus is silently lost. Measured 01-10-2026.
			state.focusRequested = true;
		},
		onKeydown( event ) {
			// Escape only closes a REOPENED notice: the first time a choice is required.
			if ( event.key === 'Escape' && choice !== null ) {
				state.isOpen = false;
				opener?.focus();
				opener = null;
			}
		},
	},
	callbacks: {
		focusOnOpen() {
			if ( state.isOpen && state.focusRequested ) {
				state.focusRequested = false;
				getElement().ref.querySelector( '#estrategia-web-consent-title' )?.focus();
			}
		},
		init() {
			choice = parseChoice( readCookie( state.cookieName ), state.revision, state.categories );
			// 1.0.0: the cookie from before the rename (armazon_consent). If it
			// is valid, it is moved to the new one and deleted: no asking again.
			if ( choice === null && state.legacyCookie ) {
				const legacy = parseChoice( readCookie( state.legacyCookie ), state.revision, state.categories );
				if ( legacy !== null ) {
					choice = legacy;
					writeCookie();
					document.cookie = `${ state.legacyCookie }=; Max-Age=0; Path=/; SameSite=Lax`;
				}
			}
			window.estrategiaWebConsent = {
				categories: [ ...state.categories ],
				has: ( category ) => normalize( category ) === 'necessary' || ( choice || [] ).includes( normalize( category ) ),
				get: () => ( choice ? [ ...choice ] : null ),
				open: () => actions.open(),
				grant: ( requested ) => {
					const category = normalize( requested );
					if ( ! state.categories.includes( category ) || ( choice || [] ).includes( category ) ) {
						return;
					}
					choice = state.categories.filter( ( item ) => item === category || ( choice || [] ).includes( item ) );
					state.draft = [ ...new Set( [ ...state.draft, category ] ) ];
					writeCookie();
					apply( choice );
				},
			};
			// 1.1.0: reopen from any marked link or button (see the header).
			document.addEventListener( 'click', ( event ) => {
				const trigger = event.target instanceof Element ? event.target.closest( OPEN_SELECTOR ) : null;
				if ( trigger ) {
					event.preventDefault();
					actions.open();
				}
			} );
			// 0.5.0: «I am ready», also WITHOUT a choice (null), which does not fire estrategia-web:consent.
			// Matomo needs it to forget an expired consent: hydration may arrive
			// AFTER window.load (measured: 1 in 3 loads).
			document.dispatchEvent( new CustomEvent( 'estrategia-web:consent-ready', { detail: { accepted: choice ? [ ...choice ] : null } } ) );
			if ( choice === null ) {
				state.draft = [];
				state.isOpen = true;
				return;
			}
			apply( choice );
		},
	},
} );
