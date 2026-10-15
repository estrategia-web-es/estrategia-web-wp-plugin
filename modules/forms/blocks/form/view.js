/**
 * The browser side of the form, with the Interactivity API.
 *
 * It is an ES module that WordPress loads as is (viewScriptModule in
 * block.json): there is no build step. Without JavaScript the form works
 * the same —the <form> posts to the same endpoint and comes back with a
 * redirect—; this only avoids the reload.
 *
 * What it does:
 * - submit: sends the form with fetch, shows the message in the aria-live
 *   area and marks the fields the server reports as invalid.
 * - start (first focus): if the page comes from an old cache and its token
 *   is older than 12 hours, it asks for a new one. It does not always ask:
 *   the token carries the time the page was rendered, which is what measures
 *   the minimum filling time, and renewing it on first focus would punish
 *   whoever fills it in quickly with autocomplete.
 *
 * How to maintain: the texts this file shows come from the server (response
 * messages and context.networkError), never written here — that way they are
 * translated with the .po and with WPML like everything else.
 * The trap: the actions are generators (function*) so as not to lose the
 * context after each yield; with async/await, getContext() would fail after
 * the first await. And submit needs withSyncEvent because it calls
 * preventDefault() synchronously.
 */
import { store, getContext, getElement, withSyncEvent } from '@wordpress/interactivity';

const STALE_SECONDS = 12 * 60 * 60;

function clearErrors( form ) {
	form.querySelectorAll( '[data-af-error]' ).forEach( ( el ) => {
		el.textContent = '';
	} );
	form.querySelectorAll( '[aria-invalid="true"]' ).forEach( ( el ) => {
		el.removeAttribute( 'aria-invalid' );
	} );
}

function showErrors( form, errors ) {
	let first = null;
	Object.entries( errors ).forEach( ( [ name, message ] ) => {
		const holder = form.querySelector( `[data-af-error="${ CSS.escape( name ) }"]` );
		const input = form.elements.namedItem( `fields[${ name }]` );
		if ( holder ) {
			holder.textContent = message;
		}
		if ( input && input.setAttribute ) {
			input.setAttribute( 'aria-invalid', 'true' );
			first = first || input;
		}
	} );
	if ( first ) {
		first.focus();
	}
}

store( 'estrategia-web-forms', {
	actions: {
		*start() {
			const ctx = getContext();
			if ( ctx.started ) {
				return;
			}
			ctx.started = true;
			const form = getElement().ref;
			const tokenInput = form.querySelector( '[name="_af_token"]' );
			const issued = parseInt( ( tokenInput.value || '0' ).split( '.' )[ 0 ], 10 );
			if ( Date.now() / 1000 - issued < STALE_SECONDS ) {
				return;
			}
			try {
				const def = form.querySelector( '[name="_af_def"]' ).value;
				const url = new URL( ctx.tokenUrl, window.location.href );
				url.searchParams.set( 'def', def );
				const response = yield fetch( url, { credentials: 'omit' } );
				if ( response.ok ) {
					const data = yield response.json();
					if ( data.token ) {
						tokenInput.value = data.token;
					}
				}
			} catch ( e ) {
				// Without a new token it is sent with the old one and the server will say if it expired.
			}
		},

		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const ctx = getContext();
			const form = getElement().ref;
			if ( ctx.busy ) {
				return;
			}
			ctx.busy = true;
			ctx.message = '';
			ctx.isError = false;
			clearErrors( form );

			const body = new FormData( form );
			body.append( '_af_js', '1' );
			try {
				const response = yield fetch( form.action, {
					method: 'POST',
					body,
					credentials: 'omit',
					headers: { Accept: 'application/json' },
				} );
				const data = yield response.json();
				ctx.isError = ! data.ok;
				ctx.message = data.message || '';
				if ( data.ok ) {
					form.reset();
					// 1.1.0: signal for whoever measures conversions (modules/google-tags).
					// Only the form name, never what was typed. Without JS (submission
					// with redirect) there is no event: that conversion is not counted.
					document.dispatchEvent( new CustomEvent( 'estrategia-web:form-sent', { detail: { form: form.getAttribute( 'id' ) || '' } } ) );
				} else {
					showErrors( form, data.errors || {} );
				}
			} catch ( e ) {
				ctx.isError = true;
				ctx.message = ctx.networkError;
			} finally {
				ctx.busy = false;
			}
		} ),
	},
} );
