/**
 * La mitad de EDITOR de las dos fuentes de Block Bindings del plugin (la
 * mitad de la web está en PHP):
 *
 *   estrategia-web-site/company   datos de la empresa   includes/class-bindings.php
 *   estrategia-web-site/location  datos de UNA sede     includes/class-location.php  (0.2.0)
 *
 * Qué hace: que un bloque enlazado ENSEÑE el valor en el editor (getValues)
 * y que la fuente aparezca en el panel «Atributos» del bloque con la lista
 * de datos (getFieldsList). Los valores y sus nombres los pone PHP en
 * window.estrategiaWebSiteCompany y window.estrategiaWebSiteLocation antes de este script.
 *
 * SIN PASO DE COMPILACIÓN, como el plugin de formularios: script clásico que
 * lee los globales wp.* del editor. No lleva textos propios (el nombre de la
 * fuente y de cada dato vienen de PHP ya traducidos), así que no necesita
 * JSON de traducciones.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien toque una de las dos fuentes en PHP, en el mismo
 *   commit, y con la prueba de nivel 3 (tests/editor.test.mjs) en verde.
 * - Una clave nueva no se toca aquí: sale de Company::keys()/labels() y de
 *   Location::labels().
 * - Caso frontera: el dato está vacío, o la sede no existe o está en
 *   borrador. Se enseña «[Nombre del dato]» en el editor para que se vea qué
 *   está enlazado; en la web sale vacío. Una sede creada DESPUÉS de abrir el
 *   editor no está en window.estrategiaWebSiteLocation hasta recargar: sale entre
 *   corchetes y en la web sí se ve.
 * - La trampa: no hay setValues y canUserEditValue es false a propósito. Si
 *   fuera editable, el editor dejaría escribir en el párrafo enlazado y lo
 *   guardaría… en ninguna parte: los datos se cambian en «Datos de la
 *   empresa» o en la sede. (Medido en WP 7.1: sin setValues el núcleo ni
 *   siquiera guarda canUserEditValue; se deja por claridad.)
 * - Y la otra: usesContext tiene que repetir lo que dice PHP. Sin él el
 *   núcleo lo deja en [null] al fusionar con el registro de PHP, y la fuente
 *   de sedes no recibiría el postId de la Consulta.
 */
( function ( wp, company, location ) {
	if ( ! wp || ! wp.blocks || ! wp.blocks.registerBlockBindingsSource ) {
		return;
	}

	// Un valor para el atributo: en una URL no se enseña el marcador entre
	// corchetes (sería un enlace roto); en un texto sí, para ver qué está enlazado.
	const show = ( attribute, value, labels, key ) => {
		if ( attribute === 'url' ) {
			return value || '';
		}
		return value || '[' + ( labels[ key ] || key ) + ']';
	};

	const fields = ( labels ) =>
		Object.keys( labels ).map( ( key ) => ( {
			label: labels[ key ],
			type: 'string',
			args: { key },
		} ) );

	if ( company ) {
		wp.blocks.registerBlockBindingsSource( {
			name: company.source,
			usesContext: [],
			getValues( { bindings } ) {
				const out = {};
				for ( const [ attribute, binding ] of Object.entries( bindings || {} ) ) {
					const key = binding && binding.args ? binding.args.key : '';
					out[ attribute ] = show( attribute, company.values[ key ], company.labels, key );
				}
				return out;
			},
			canUserEditValue() {
				return false;
			},
			getFieldsList() {
				return fields( company.labels );
			},
		} );
	}

	if ( location ) {
		// Con slug, esa sede; sin slug, la del contexto (la fila de una Consulta).
		const find = ( args, context ) => {
			if ( args.slug ) {
				return location.locations.find( ( l ) => l.slug === args.slug );
			}
			const id = Number( context && context.postId );
			return location.locations.find( ( l ) => l.id === id );
		};
		wp.blocks.registerBlockBindingsSource( {
			name: location.source,
			usesContext: [ 'postId', 'postType' ],
			getValues( { bindings, context } ) {
				const out = {};
				for ( const [ attribute, binding ] of Object.entries( bindings || {} ) ) {
					const args = ( binding && binding.args ) || {};
					const sede = find( args, context );
					out[ attribute ] = show( attribute, sede ? sede.values[ args.key ] : '', location.labels, args.key );
				}
				return out;
			},
			canUserEditValue() {
				return false;
			},
			getFieldsList() {
				return fields( location.labels );
			},
		} );
	}
} )( window.wp, window.estrategiaWebSiteCompany, window.estrategiaWebSiteLocation );
