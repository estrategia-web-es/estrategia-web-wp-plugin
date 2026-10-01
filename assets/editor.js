/**
 * La mitad de EDITOR de la fuente de Block Bindings `armazon-site/company`
 * (la mitad de la web está en includes/class-bindings.php).
 *
 * Qué hace: que un bloque enlazado a un dato de la empresa ENSEÑE el valor
 * en el editor (getValues) y que la fuente aparezca en el panel «Atributos»
 * del bloque con la lista de datos (getFieldsList). Los valores y sus nombres
 * los pone PHP en window.armazonSiteCompany antes de este script.
 *
 * SIN PASO DE COMPILACIÓN, como el plugin de formularios: script clásico que
 * lee los globales wp.* del editor. No lleva textos propios (el nombre de la
 * fuente y de cada dato vienen de PHP ya traducidos), así que no necesita
 * JSON de traducciones.
 *
 * Cómo se mantiene:
 * - Una clave nueva no se toca aquí: sale de Company::keys() y labels().
 * - Caso frontera: el dato está vacío. Se enseña «[Nombre del dato]» en el
 *   editor para que se vea qué está enlazado; en la web sale vacío.
 * - La trampa: no hay setValues y canUserEditValue es false a propósito. Si
 *   fuera editable, el editor dejaría escribir en el párrafo enlazado y lo
 *   guardaría… en ninguna parte: los datos se cambian en «Datos de la
 *   empresa». (Medido en WP 7.1: sin setValues el núcleo ni siquiera guarda
 *   canUserEditValue; se deja por claridad.)
 */
( function ( wp, data ) {
	if ( ! wp || ! wp.blocks || ! wp.blocks.registerBlockBindingsSource || ! data ) {
		return;
	}

	const display = ( key ) => {
		const value = data.values[ key ];
		if ( value ) {
			return value;
		}
		return '[' + ( data.labels[ key ] || key ) + ']';
	};

	wp.blocks.registerBlockBindingsSource( {
		name: data.source,
		// Sin esto el núcleo lo deja en [null] al fusionar con el registro de PHP.
		usesContext: [],
		getValues( { bindings } ) {
			const out = {};
			for ( const [ attribute, binding ] of Object.entries( bindings || {} ) ) {
				const key = binding && binding.args ? binding.args.key : '';
				// En una URL no se enseña el marcador entre corchetes: sería un enlace roto.
				out[ attribute ] = attribute === 'url' ? data.values[ key ] || '' : display( key );
			}
			return out;
		},
		canUserEditValue() {
			return false;
		},
		getFieldsList() {
			return Object.keys( data.labels ).map( ( key ) => ( {
				label: data.labels[ key ],
				type: 'string',
				args: { key },
			} ) );
		},
	} );
} )( window.wp, window.armazonSiteCompany );
