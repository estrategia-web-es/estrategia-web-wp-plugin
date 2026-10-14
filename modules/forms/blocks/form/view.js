/**
 * El lado del navegador del formulario, con la Interactivity API.
 *
 * Es un módulo ES que WordPress carga tal cual (viewScriptModule en
 * block.json): no hay paso de compilación. Sin JavaScript el formulario
 * funciona igual —el <form> manda al mismo endpoint y vuelve con una
 * redirección—; esto solo evita la recarga.
 *
 * Qué hace:
 * - submit: manda el formulario con fetch, pinta el mensaje en la zona
 *   aria-live y marca los campos con error que diga el servidor.
 * - start (primer foco): si la página viene de una caché vieja y su token
 *   tiene más de 12 horas, pide uno nuevo. No lo pide siempre: el token lleva
 *   la hora en que se pintó la página, que es la que mide el tiempo mínimo
 *   de relleno, y renovarlo al primer foco castigaría al que rellena rápido
 *   con el autocompletado.
 *
 * Cómo se mantiene: los textos que este fichero enseña vienen del servidor
 * (mensajes de la respuesta y context.networkError), nunca escritos aquí —
 * así se traducen con el .po y con WPML como todo lo demás.
 * La trampa: las acciones son generadores (function*) para no perder el
 * contexto tras cada yield; con async/await, getContext() fallaría después
 * del primer await. Y submit necesita withSyncEvent porque llama a
 * preventDefault() de forma síncrona.
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
				// Sin token nuevo se manda con el viejo y el servidor dirá si caducó.
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
					// 1.1.0: aviso para quien mida conversiones (modules/google-tags).
					// Solo el nombre del formulario, nunca lo escrito. Sin JS (envío
					// con redirección) no hay evento: esa conversión no se cuenta.
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
