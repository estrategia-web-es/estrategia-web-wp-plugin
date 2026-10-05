/**
 * Aviso de cookies — comportamiento (Interactivity API, store «armazon/consent»).
 *
 * La documentación del módulo (categorías, API, WP Consent API, trampas) está
 * en la cabecera de includes/class-consent.php. Aquí, lo que solo afecta a
 * este fichero:
 *
 * - Vino del tema padre (armazon-wp-padre ≤ 0.6.0, blocks/consent/view.js) en
 *   armazon-site 0.5.0 SIN cambiar el contrato: mismo store, misma cookie,
 *   mismo formato, misma API y mismo evento. Un visitante que ya eligió con
 *   el aviso del padre no vuelve a ver el aviso.
 * - La elección se guarda en la cookie técnica «armazon_consent» con el
 *   formato "<revisión>.<cat~cat>.<unix>". El mismo formato lo lee PHP
 *   (Consent::choice): si se cambia aquí, se cambia allí.
 * - API pública, para el tema, Matomo (includes/class-matomo.php) y otros
 *   bloques (armazon/map del padre la usa):
 *     window.armazonConsent.categories     → categorías que pregunta el aviso
 *     window.armazonConsent.has( 'maps' )  → boolean
 *     window.armazonConsent.get()          → string[] | null (null = sin elegir)
 *     window.armazonConsent.open()         → reabre el aviso
 *     window.armazonConsent.grant( 'maps' ) → añade UNA categoría a la
 *       elección (la usa «Ver mapa»). Si la web no la declara, no hace nada.
 *       No cierra el aviso si estaba abierto: el visitante sigue eligiendo el
 *       resto; si luego pulsa «Rechazar todas», la retira.
 *     document.addEventListener( 'armazon:consent', ( e ) => e.detail.accepted )
 *   El evento se lanza al arrancar (si ya había elección) y en cada cambio.
 *     document.addEventListener( 'armazon:consent-ready', ( e ) => e.detail.accepted )
 *   (0.5.0, nuevo) se lanza UNA vez al arrancar, haya elección o no
 *   (accepted = null si no la hay), justo después de crear window.armazonConsent.
 *   Es para quien necesita enterarse de «sin elegir» (Matomo, trampa 1).
 * - La trampa: los módulos ES se ejecutan en diferido y en orden de llegada;
 *   otro módulo (o el script de Matomo) puede arrancar ANTES que este. Quien
 *   consulte el consentimiento tiene que mirar window.armazonConsent si existe
 *   Y escuchar el evento, nunca solo una de las dos cosas.
 */
import { store, getContext, getElement, withSyncEvent } from '@wordpress/interactivity';

let choice = null;
let opener = null;

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

/** Turns <script type="text/plain" data-armazon-consent="x"> into real scripts. */
const activateScripts = ( accepted ) => {
	document.querySelectorAll( 'script[type="text/plain"][data-armazon-consent]:not([data-armazon-activated])' ).forEach( ( placeholder ) => {
		if ( ! accepted.includes( placeholder.dataset.armazonConsent ) ) {
			return;
		}
		const script = document.createElement( 'script' );
		for ( const { name, value } of placeholder.attributes ) {
			if ( ! [ 'type', 'data-armazon-consent', 'data-armazon-type' ].includes( name ) ) {
				script.setAttribute( name, value );
			}
		}
		script.type = placeholder.dataset.armazonType || 'text/javascript';
		script.text = placeholder.text;
		placeholder.dataset.armazonActivated = '';
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
};

const apply = ( accepted ) => {
	activateScripts( accepted );
	tellWpConsentApi( accepted );
	document.dispatchEvent( new CustomEvent( 'armazon:consent', { detail: { accepted: [ ...accepted ] } } ) );
};

/** Saves the current choice in the technical cookie (format: see the header). */
const writeCookie = () => {
	const value = `${ state.revision }.${ choice.join( '~' ) }.${ Math.floor( Date.now() / 1000 ) }`;
	const secure = window.location.protocol === 'https:' ? '; Secure' : '';
	document.cookie = `${ state.cookieName }=${ encodeURIComponent( value ) }; Max-Age=${ state.maxAge }; Path=/; SameSite=Lax${ secure }`;
};

const { state, actions } = store( 'armazon/consent', {
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
				document.querySelector( `script[data-armazon-consent="${ category }"][data-armazon-activated]` )
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
				getElement().ref.querySelector( '#armazon-consent-title' )?.focus();
			}
		},
		init() {
			choice = parseChoice( readCookie( state.cookieName ), state.revision, state.categories );
			window.armazonConsent = {
				categories: [ ...state.categories ],
				has: ( category ) => category === 'necessary' || ( choice || [] ).includes( category ),
				get: () => ( choice ? [ ...choice ] : null ),
				open: () => actions.open(),
				grant: ( category ) => {
					if ( ! state.categories.includes( category ) || ( choice || [] ).includes( category ) ) {
						return;
					}
					choice = state.categories.filter( ( item ) => item === category || ( choice || [] ).includes( item ) );
					state.draft = [ ...new Set( [ ...state.draft, category ] ) ];
					writeCookie();
					apply( choice );
				},
			};
			// 0.5.0: «ya estoy», también SIN elección (null), que no lanza armazon:consent.
			// Matomo lo necesita para olvidar un consentimiento caducado: la hidratación
			// puede llegar DESPUÉS de window.load (medido: 1 de cada 3 cargas).
			document.dispatchEvent( new CustomEvent( 'armazon:consent-ready', { detail: { accepted: choice ? [ ...choice ] : null } } ) );
			if ( choice === null ) {
				state.draft = [];
				state.isOpen = true;
				return;
			}
			apply( choice );
		},
	},
} );
