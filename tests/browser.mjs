// NIVEL: BANCO DE TRABAJO — not a test. The runner of the browser scenarios (modules/<module>/tests/browser/NN-name.json): one browser, the requested scenarios, and what they see is saved for the checks.
/**
 * THE BROWSER TESTS ARE JSON (David, 29-10-2026, ARMAZON row 7, «Los niveles
 * se IMPONEN», point 7): «un JSON o una batería de JSONs que se definen y
 * después se ejecutan, y el navegador se levanta y va ejecutando los pasos del
 * JSON… siempre guiado con un único navegador levantado que ejecuta todas las
 * pruebas a demanda».
 *
 *     node tests/browser.mjs                  every scenario
 *     node tests/browser.mjs 1 2 4            by number (the NN of NN-name.json)
 *     node tests/browser.mjs carousel/ map-   by module («carousel/») or by name (or part of it)
 *     node tests/browser.mjs --list           which scenarios there are, and how many steps
 *     node tests/browser.mjs 3 --check        and then the checks of the ones it ran
 *     node tests/browser.mjs 3 --base http://127.0.0.1:9421
 *
 * Against --base, else AS_BASE, else http://127.0.0.1:9404. It does NOT start
 * a WordPress: `node tests/run.mjs --serve` keeps one up (and the battery,
 * tests/run.mjs, runs the scenarios on its shards). EW_BROWSER_OUT changes
 * where it saves; EW_BROWSER_TABS how many units of ONE scenario run at once (3).
 *
 * WHERE THIS COMES FROM (the source of truth). It is a COPY, adapted, of
 * Lavandería's tests/navegador.mjs (E:/Repositories/lavanderia-mallorca,
 * commit 19bc7c0, 29-10-2026), the pilot of the mechanism; the rule itself is
 * ARMAZON.md, row 7, «Los niveles se IMPONEN», point 7. Copied, not imported:
 * the two repos cannot import each other today (each runs its own tests on
 * its own machine paths), and this one needs things Lavandería does not (a
 * WordPress state per scenario, editor boots, touch, drag, request facts) and
 * none of what only Lavandería has (its nine pages, the loaded copy, the
 * production host, three repos for freshness). WHEN ONE CHANGES THE
 * MECHANISM (the step vocabulary, the «ready» facts, the freshness rule, the
 * no-fixed-wait rule), the other gets the same change or a line in its header
 * saying why not. What differs on purpose, here: the English, the setup
 * customStep, the scenarios living in their module, and the extra
 * customSteps below.
 *
 * THREE PIECES:
 * 1. SCENARIO = modules/<module>/tests/browser/NN-name.json (NN unique in the
 *    whole repo), in the format of Chrome DevTools → Recorder (the «user
 *    flow» of @puppeteer/replay: { title, timeout?, selectorAttribute?,
 *    steps }). Not a format of ours: it is RECORDED in the Recorder and
 *    exported. Plus our top keys, which the Recorder ignores:
 *      description  REQUIRED: which problem it watches, and which test it replaced;
 *      widths       repeats the scenario at those widths (each setViewport takes the unit's width);
 *      writes       true if it writes in WordPress (every scenario with a setup does): local only;
 *      network      { foreign: "abort", fulfill: [ { url: regex, status, contentType, body: "@repo/path" } ],
 *                     rewrite: [ { url: regex, find, replace } ] (a document's text, found or not is saved),
 *                     bodies: [ regex ] (the response bodies of those requests are saved) };
 *      initScripts  [ "modules/…/x.js" ]: files added to every page of the unit before its own scripts
 *                   (probes that record from the first instant: events, frames);
 *      emulate      { reducedMotion: "reduce", javaScriptEnabled: false };
 *      dialogs      "accept" (confirm() and friends; Playwright's default dismisses them).
 *    The first setViewport makes the unit's context: width, height and also
 *    deviceScaleFactor, isMobile, hasTouch (a later setViewport only resizes).
 * 2. RUNNER = this file: ONE Chromium (Playwright), each unit (scenario ×
 *    width) in a NEW context (cookies isolated), the scenarios one after
 *    another (they share one WordPress and each starts by setting it), the
 *    units of one scenario up to EW_BROWSER_TABS at once. Standard steps:
 *    setViewport, navigate, click, doubleClick, change, keyDown, keyUp,
 *    hover, scroll, waitForElement, waitForExpression, close. Ours, as
 *    customStep:
 *      setup          { requests: [ { path, method?, body?, as? } ] }  WordPress state, see below
 *      capture        { label, save: [html, console, network, cookies, screenshot, blocks, aria], boxes, values?, settle?, patterns? }
 *      scrollIntoView { selectors, optional }   (optional: if it is not on the page NOW, skipped)
 *      run            { expression }   JavaScript the Recorder cannot record: wp.data, a cookie, a probe
 *      request        { url: regex, method?, status?, count? }   the FACT that N such requests finished
 *      touch          { selectors, dx, y? }   a real finger: start, a move per frame, still, lift
 *      drag           { selectors, to: { selectors, x, y } }   (y < 0: from the bottom of the target)
 *      cookies        { set: [ { name, value, path? } ] }   before navigating (the context's cookies)
 *    save «blocks» (in the block editor): wp.blocks.parse() of every page and
 *    synced pattern, and of the patterns whose name starts with `patterns`;
 *    the invalid ones go to blocks.json (validateBlocks()).
 *    values: { name: "expression" } evaluated in the page AFTER «ready» and
 *    saved in values.json (a promise is awaited): what a check needs that is
 *    not a box (wp.data, dataLayer, document.activeElement, a probe's record).
 *    settle: which in-flight requests prevent capturing (URL regexes, of any
 *    type, images included; absent: every one of BLOCKING_TYPES; [] none). Edge case with a name: the
 *    EDITOR is never without requests (heartbeat, REST); an editor capture
 *    says settle [] after waiting for the fact that matters.
 *    A step type or customStep this file does not know = error (said by
 *    tests/scenarios.test.mjs, level 1, before opening anything).
 *    Saves in tests/.cache/browser/<scenario>/<label>@<width>/: page.json
 *    (always: url, status, lang, title, scrollWidth, clientWidth,
 *    innerWidth/Height, scrollY/Height), html.html (the DOM after the JS),
 *    console.json (ACCUMULATED from the start of the unit: console messages
 *    and page errors), network.json (the requests SINCE THE PREVIOUS CAPTURE,
 *    with outcome ok/aborted/simulated/rewritten/failed, the request body of
 *    a POST, Chromium's initial priority, and the response body when asked),
 *    cookies.json, boxes.json, values.json, aria.yml (the accessibility tree
 *    as Playwright reads it: role "accessible name", which the DOM does not
 *    say) and screenshot.png. And
 *    <scenario>/scenario.json: when, against what, how long each unit and
 *    each step took, the state of the code and whether a step failed;
 *    <scenario>/setup.json, what the setup requests answered.
 * 3. CHECKS = modules/<module>/tests/browser-<name>.test.mjs, LEVEL 1: they
 *    read what was saved with artifacts() (below) and assert. The first line
 *    says which scenario they read: «// NIVEL: 1 — reads the browser scenario
 *    «NN-name»: …». tests/run.mjs runs them AFTER the scenarios.
 *
 * THE SETUP (the WordPress a scenario needs). A scenario starts with one or
 * more customStep «setup» (and only there: before any other step). Each
 * request goes to <base>/wp-json<path> from Node, IN ORDER, ONCE per scenario
 * (not per unit), before its first unit; a non-2xx answer fails the scenario.
 * They are the existing test routes: /estrategia-web-site-test/v1/setup
 * (reset + modules + company + consent + options + pages in one request,
 * tests/wp/estrategia-web-site-test.php), a module's seed
 * (/estrategia-web-carousel-test/v1/seed…), /estrategia-web-forms-test/v1/reset,
 * /estrategia-web-ct-test/v1/batch (REST calls as the administrator). «as»
 * names an answer, and any string of a later step (or of a later setup
 * request) can use it: «{{@name.path}}» (e.g. a post id in a navigate url:
 * …post={{@seed.pages.page.id}}). «{{@now}}» is the Unix time in seconds (a
 * cookie's timestamp). The «@» is on purpose: the forms' markers ({{email}})
 * and the forms seed's «{{id:key}}» travel in setup bodies untouched.
 *
 * NEVER A FIXED WAIT (David, 29-10-2026: «nunca sabemos si basta o no basta;
 * casi siempre está sobredimensionado. Las esperas tienen que ser de
 * eventos»). Neither pauses nor Playwright's «network idle» (500 ms without
 * requests: a fixed time in disguise). We wait for FACTS:
 * - navigate: the page's load event;
 * - capture: «page ready» = document.readyState «complete», the fonts loaded
 *   (document.fonts.status «loaded»), the images touching the viewport with
 *   complete = true, and no request in flight of type document, script,
 *   stylesheet, fetch, xhr or font (counted here with request /
 *   requestfinished / requestfailed). Repeated until everything holds at
 *   once: a finished request may ask for another one;
 * - waitForElement / waitForExpression: what they say (an expression is
 *   tried on every animation frame);
 * - request: N requests that match have FINISHED;
 * - touch: each move waits for the next animation frame (a frame, not a time).
 * The timeouts (of the step, of the scenario, or here: 10 s, 60 s a navigate)
 * are failure CEILINGS, never waits: if the fact does not come, the step fails
 * naming the fact. tests/fixed-waits.test.mjs (level 1) stops the battery
 * if someone writes a fixed wait here, in a scenario or in a check.
 *
 * RECORDING A NEW SCENARIO:
 * 1. node tests/run.mjs --serve (the WordPress on 9404), and its setup
 *    request by hand (or the setup step of a similar scenario).
 * 2. Chrome → DevTools → Recorder → «Create a new recording», do the journey
 *    and stop. Export → «JSON».
 * 3. Save it as modules/<module>/tests/browser/NN-name.json (the next free
 *    number: node tests/browser.mjs --list), add description, writes and the
 *    setup step on top (and widths, network, emulate… if needed).
 * 4. Add a capture where there is something to look at, with the boxes and
 *    values the check will need. Remove what the Recorder adds and is not
 *    needed (loose clicks to focus, hand scrolls).
 * 5. node tests/browser.mjs NN and look at what was saved; write
 *    modules/<module>/tests/browser-<name>.test.mjs and see it RED once
 *    (break an expected value, see the red, restore it; say how in its header).
 *
 * How to maintain it:
 * - A new check that needs the page painted: first look whether it fits in a
 *   capture that already exists (one more box or value). Another scenario is
 *   another setup and another load.
 * - The Recorder's selectors: aria/Name[role="button"] (role + accessible
 *   name; without a role, exact text or label), plain CSS, xpath/…, pierce/…
 *   (CSS: Playwright already pierces the open shadow DOM) and text/…. A
 *   selector that is a LIST is a chain (inside of, inside of). They are tried
 *   in order and the first that finds something wins, as in the Recorder. A
 *   click lands at offsetX/offsetY, clipped to the box. «frame» (the
 *   Recorder's child-frame indexes) or a selector chain cannot reach into the
 *   editor canvas reliably: what is read there is read with values
 *   (document.querySelector('iframe[name=editor-canvas]').contentDocument…)
 *   or boxes with «frame».
 * - FRESHNESS (artifacts()): a check FAILS if what was saved is older than the
 *   code: if the scenario's JSON changed, if the last commit that touches the
 *   code (CODE below: the whole repo except the checks, the scenarios
 *   themselves, *.md and docs/) is not the one written down when it ran, or if
 *   an uncommitted file there was touched after the run started. Edge case
 *   with a name: a commit that only changes a check or a README does NOT
 *   make it stale; one that changes a test mu-plugin, this runner or a single
 *   comment of the plugin does.
 * - WHAT WRITES (writes: true) only runs against 127.0.0.1/localhost: it is
 *   refused against any other host.
 * - THE TRAP: the URLs the Recorder records are ABSOLUTE (with the host it
 *   was recorded on). A local one is moved to the base: a scenario recorded
 *   on 9404 runs on the shard's 9421.
 * - The other trap (Lavandería, 06-10-2026): a theme with scroll-behavior:
 *   smooth on the root ANIMATES a plain scroll. Every scroll here is
 *   behavior «instant».
 * - The trap of the setup and the units: the setup runs ONCE, before the
 *   units, which may run at once; a unit that writes what another unit reads
 *   is a second scenario, not a width.
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

export const ROOT = path.resolve( import.meta.dirname, '..' );
export const OUT_DIR = path.resolve( ROOT, process.env.EW_BROWSER_OUT || 'tests/.cache/browser' );
const LOCAL_HOSTS = [ '127.0.0.1', 'localhost' ];
const DEFAULT_BASE = 'http://127.0.0.1:9404';
const STEP_TIMEOUT = 10000;
const NAVIGATE_TIMEOUT = 60000;
const TABS = Number( process.env.EW_BROWSER_TABS || 3 );
// The requests that keep a page NOT ready while in flight (images do not: lazy ones never finish if not seen).
const BLOCKING_TYPES = [ 'document', 'script', 'stylesheet', 'fetch', 'xhr', 'font' ];
// Playwright without node_modules of its own: portal-hs's (CLAUDE.md 3.9), as tests/lib.mjs.
const playwright = () => createRequire( 'E:/Repositories/portal-hs/package.json' )( 'playwright' );

// ------------------------------------------------------------- scenarios

const STANDARD = {
	setViewport: { required: [ 'width', 'height' ] },
	navigate: { required: [ 'url' ] },
	click: { required: [ 'selectors', 'offsetX', 'offsetY' ] },
	doubleClick: { required: [ 'selectors', 'offsetX', 'offsetY' ] },
	change: { required: [ 'selectors', 'value' ] },
	keyDown: { required: [ 'key' ] },
	keyUp: { required: [ 'key' ] },
	hover: { required: [ 'selectors' ] },
	scroll: { required: [] },
	waitForElement: { required: [ 'selectors' ] },
	waitForExpression: { required: [ 'expression' ] },
	close: { required: [] },
	customStep: { required: [ 'name', 'parameters' ] },
};
const CUSTOM = {
	setup: [ 'requests' ],
	capture: [ 'label', 'save', 'boxes', 'values', 'settle', 'patterns' ],
	scrollIntoView: [ 'selectors', 'optional' ],
	run: [ 'expression' ],
	request: [ 'url', 'method', 'status', 'count' ],
	touch: [ 'selectors', 'dx', 'y' ],
	drag: [ 'selectors', 'to' ],
	cookies: [ 'set' ],
};
const SAVE = [ 'html', 'console', 'network', 'cookies', 'screenshot', 'blocks', 'aria' ];
const BOX_KEYS = [ 'name', 'selector', 'first', 'frame', 'styles', 'pseudo', 'attrs', 'props', 'within', 'ink', 'lines', 'background', 'inViewport' ];
const TOP_KEYS = [ 'title', 'timeout', 'selectorAttribute', 'steps', 'description', 'widths', 'writes', 'network', 'initScripts', 'emulate', 'dialogs' ];
// «{{@name.path}}»: the «@» keeps it apart from the forms' own markers ({{email}}, {{id:key}}), which travel in setup bodies.
const PLACEHOLDER = /\{\{@([^{}]+)\}\}/g;

/** Errors of a scenario (empty = valid). The schema is @puppeteer/replay's UserFlow plus our keys and customSteps. */
export function validate( flow ) {
	const errors = [];
	const bad = ( m ) => errors.push( m );
	if ( ! flow || 'object' !== typeof flow ) {
		return [ 'not an object' ];
	}
	Object.keys( flow ).filter( ( k ) => ! TOP_KEYS.includes( k ) ).forEach( ( k ) => bad( `unknown top key «${ k }»` ) );
	'string' === typeof flow.title || bad( 'no title' );
	( 'string' === typeof flow.description && flow.description.length > 20 ) || bad( 'no description (which problem it watches)' );
	( Array.isArray( flow.steps ) && flow.steps.length ) || bad( 'no steps' );
	if ( flow.widths !== undefined && ! ( Array.isArray( flow.widths ) && flow.widths.every( Number.isInteger ) ) ) {
		bad( 'widths: a list of whole widths' );
	}
	if ( flow.network ) {
		Object.keys( flow.network ).filter( ( k ) => ! [ 'foreign', 'fulfill', 'rewrite', 'bodies' ].includes( k ) ).forEach( ( k ) => bad( `network: unknown key «${ k }»` ) );
		if ( flow.network.foreign && 'abort' !== flow.network.foreign ) {
			bad( 'network.foreign: only «abort»' );
		}
		for ( const f of flow.network.fulfill || [] ) {
			Object.keys( f ).filter( ( k ) => ! [ 'url', 'status', 'contentType', 'body' ].includes( k ) ).forEach( ( k ) => bad( `network.fulfill: unknown key «${ k }»` ) );
			'string' === typeof f.url || bad( 'network.fulfill: no url (a regular expression)' );
			if ( f.body && ! fs.existsSync( fileOf( f.body ) ) ) {
				bad( `network.fulfill: ${ f.body } does not exist` );
			}
		}
		for ( const r of flow.network.rewrite || [] ) {
			Object.keys( r ).filter( ( k ) => ! [ 'url', 'find', 'replace' ].includes( k ) ).forEach( ( k ) => bad( `network.rewrite: unknown key «${ k }»` ) );
			( 'string' === typeof r.url && 'string' === typeof r.find && 'string' === typeof r.replace ) || bad( 'network.rewrite: url, find and replace' );
		}
		if ( flow.network.bodies && ! ( Array.isArray( flow.network.bodies ) && flow.network.bodies.every( ( b ) => 'string' === typeof b ) ) ) {
			bad( 'network.bodies: a list of URL regexes' );
		}
	}
	for ( const s of flow.initScripts || [] ) {
		fs.existsSync( fileOf( s ) ) || bad( `initScripts: ${ s } does not exist` );
	}
	if ( flow.emulate ) {
		Object.keys( flow.emulate ).filter( ( k ) => ! [ 'reducedMotion', 'javaScriptEnabled' ].includes( k ) ).forEach( ( k ) => bad( `emulate: unknown key «${ k }»` ) );
	}
	if ( flow.dialogs !== undefined && ! [ 'accept', 'dismiss' ].includes( flow.dialogs ) ) {
		bad( 'dialogs: «accept» or «dismiss»' );
	}
	const labels = new Set();
	const names = new Set( [ 'now' ] );
	let pastSetup = false;
	let firstViewport = null;
	( flow.steps || [] ).forEach( ( step, i ) => {
		const at = `step ${ i + 1 } (${ step?.type }${ step?.name ? ' ' + step.name : '' })`;
		const spec = STANDARD[ step?.type ];
		if ( ! spec ) {
			bad( `${ at }: unknown step type` );
			return;
		}
		spec.required.filter( ( k ) => step[ k ] === undefined ).forEach( ( k ) => bad( `${ at }: no «${ k }»` ) );
		if ( step.selectors && ! ( Array.isArray( step.selectors ) && step.selectors.length && step.selectors.every( ( s ) => 'string' === typeof s || ( Array.isArray( s ) && s.every( ( x ) => 'string' === typeof x ) ) ) ) ) {
			bad( `${ at }: selectors is a list of selectors (text or list of texts)` );
		}
		if ( 'waitForElement' === step.type && step.operator && ! [ '>=', '==', '<=' ].includes( step.operator ) ) {
			bad( `${ at }: operator is >=, == or <=` );
		}
		if ( 'setViewport' === step.type ) {
			if ( ! firstViewport ) {
				firstViewport = step;
			} else if ( [ 'deviceScaleFactor', 'isMobile', 'hasTouch' ].some( ( k ) => step[ k ] !== undefined && step[ k ] !== firstViewport[ k ] ) ) {
				bad( `${ at }: only the first setViewport sets deviceScaleFactor, isMobile and hasTouch (the unit's context); a later one only resizes` );
			}
		}
		const isSetup = 'customStep' === step.type && 'setup' === step.name;
		// Placeholders name an EARLIER setup answer («as») or «now» (in a setup, an earlier request of it).
		const placeholders = ( value ) => {
			for ( const [ , ref ] of JSON.stringify( value ).matchAll( PLACEHOLDER ) ) {
				names.has( ref.split( '.' )[ 0 ] ) || bad( `${ at }: «{{@${ ref }}}» names no earlier setup answer («as»)` );
			}
		};
		if ( ! isSetup ) {
			placeholders( step );
		}
		if ( isSetup && pastSetup ) {
			bad( `${ at }: a setup goes before any other step (it runs once per scenario, before the units)` );
		}
		pastSetup ||= ! isSetup;
		if ( 'customStep' !== step.type ) {
			return;
		}
		const keys = CUSTOM[ step.name ];
		if ( ! keys ) {
			bad( `${ at }: unknown customStep (there are: ${ Object.keys( CUSTOM ).join( ', ' ) })` );
			return;
		}
		const params = step.parameters || {};
		Object.keys( params ).filter( ( k ) => ! keys.includes( k ) ).forEach( ( k ) => bad( `${ at }: unknown parameter «${ k }»` ) );
		if ( 'setup' === step.name ) {
			flow.writes === true || bad( `${ at }: a scenario with a setup writes in WordPress: «writes»: true` );
			( Array.isArray( params.requests ) && params.requests.length ) || bad( `${ at }: requests is a list` );
			for ( const r of params.requests || [] ) {
				( 'string' === typeof r.path && r.path.startsWith( '/' ) ) || bad( `${ at }: each request has a path under /wp-json (starting with /)` );
				Object.keys( r ).filter( ( k ) => ! [ 'path', 'method', 'body', 'as' ].includes( k ) ).forEach( ( k ) => bad( `${ at }: request with unknown key «${ k }»` ) );
				placeholders( r );
				if ( r.as ) {
					names.add( r.as );
				}
			}
		}
		if ( 'capture' === step.name ) {
			/^[a-z0-9-]+$/.test( params.label || '' ) || bad( `${ at }: label in lowercase, digits and hyphens` );
			labels.has( params.label ) && bad( `${ at }: label «${ params.label }» repeated` );
			labels.add( params.label );
			( params.save || [] ).filter( ( s ) => ! SAVE.includes( s ) ).forEach( ( s ) => bad( `${ at }: save «${ s }» does not exist (${ SAVE.join( ', ' ) })` ) );
			if ( params.settle !== undefined && ! ( Array.isArray( params.settle ) && params.settle.every( ( s ) => 'string' === typeof s ) ) ) {
				bad( `${ at }: settle is a list of URL regexes` );
			}
			if ( params.values !== undefined && ! ( params.values && 'object' === typeof params.values && Object.values( params.values ).every( ( v ) => 'string' === typeof v ) ) ) {
				bad( `${ at }: values is { name: "expression" }` );
			}
			for ( const b of params.boxes || [] ) {
				Object.keys( b ).filter( ( k ) => ! BOX_KEYS.includes( k ) ).forEach( ( k ) => bad( `${ at }: box «${ b.name }» with unknown key «${ k }»` ) );
				( b.name && b.selector ) || bad( `${ at }: each box has name and selector` );
			}
		}
		if ( [ 'scrollIntoView', 'touch', 'drag' ].includes( step.name ) && ! Array.isArray( params.selectors ) ) {
			bad( `${ at }: no selectors` );
		}
		if ( 'run' === step.name && 'string' !== typeof params.expression ) {
			bad( `${ at }: expression is a text` );
		}
		if ( 'request' === step.name && 'string' !== typeof params.url ) {
			bad( `${ at }: url is a regular expression` );
		}
		if ( 'touch' === step.name && ! Number.isFinite( params.dx ) ) {
			bad( `${ at }: dx is a number of pixels (> 0: the finger goes right)` );
		}
		if ( 'drag' === step.name && ! Array.isArray( params.to?.selectors ) ) {
			bad( `${ at }: to.selectors` );
		}
		if ( 'cookies' === step.name && ! ( Array.isArray( params.set ) && params.set.every( ( c ) => c.name && 'string' === typeof c.value ) ) ) {
			bad( `${ at }: set is [ { name, value } ]` );
		}
	} );
	return errors;
}

/** A file named in a scenario: «@repo/…» or a path relative to the repo. */
function fileOf( ref ) {
	return path.join( ROOT, ref.replace( /^@repo\//, '' ) );
}

/** Where the scenarios live: modules/<module>/tests/browser/ (and tests/browser/, for the core). */
export function scenarioDirs() {
	const dirs = [ [ null, path.join( ROOT, 'tests', 'browser' ) ] ];
	for ( const m of fs.readdirSync( path.join( ROOT, 'modules' ) ) ) {
		dirs.push( [ m, path.join( ROOT, 'modules', m, 'tests', 'browser' ) ] );
	}
	return dirs.filter( ( [ , d ] ) => fs.existsSync( d ) );
}

/** The scenarios: [ { id, number, name, module, file, text, flow } ], by number. */
export function listScenarios() {
	return scenarioDirs()
		.flatMap( ( [ module, dir ] ) =>
			fs
				.readdirSync( dir )
				.filter( ( f ) => /^\d{2}-[a-z0-9-]+\.json$/.test( f ) )
				.map( ( f ) => {
					const file = path.join( dir, f );
					const text = fs.readFileSync( file, 'utf8' ).replace( /^\uFEFF/, '' );
					return { id: f.replace( /\.json$/, '' ), number: Number( f.slice( 0, 2 ) ), name: f.slice( 3, -5 ), module, file, text, flow: JSON.parse( text ) };
				} )
		)
		.sort( ( a, b ) => a.number - b.number );
}

const sha = ( text ) => crypto.createHash( 'sha256' ).update( text ).digest( 'hex' ).slice( 0, 16 );

// ------------------------------------------------------- the state of the code

/**
 * What «the code» is for the freshness rule: the repo, except what cannot
 * change what the browser sees (the checks, the scenarios, which have their
 * own hash, the docs). Pathspec exclusions match at any depth.
 */
const CODE = [ '.', ':(exclude)*.test.mjs', ':(exclude)*.md', ':(exclude)docs', ':(exclude)modules/*/tests/browser/*.json', ':(exclude)tests/browser/*.json' ];
const git = ( args ) => spawnSync( 'git', [ '-C', ROOT, ...args ], { encoding: 'utf8' } ).stdout || '';

/** { commit, dirty: [ { file, mtime } ] } */
export function codeState() {
	const dirty = git( [ 'status', '--porcelain', '--untracked-files=all', '--', ...CODE ] )
		.split( '\n' )
		.filter( Boolean )
		.map( ( l ) => l.slice( 3 ).replace( /^.* -> /, '' ).replace( /^"|"$/g, '' ) )
		.map( ( file ) => {
			const full = path.join( ROOT, file );
			return { file, mtime: fs.existsSync( full ) ? fs.statSync( full ).mtimeMs : 0 };
		} );
	return { commit: git( [ 'log', '-1', '--format=%H', '--', ...CODE ] ).trim(), dirty };
}

/** Why the saved run of a scenario is older than the code (empty = fresh). */
export function staleness( meta ) {
	const why = [];
	const scen = listScenarios().find( ( s ) => s.id === meta.id );
	if ( ! scen ) {
		return [ `the scenario ${ meta.id } no longer exists` ];
	}
	if ( sha( scen.text ) !== meta.sha ) {
		why.push( `${ path.relative( ROOT, scen.file ).replace( /\\/g, '/' ) } changed after it ran` );
	}
	const now = codeState();
	if ( now.commit !== meta.code.commit ) {
		why.push( `the last commit of the code is ${ now.commit.slice( 0, 7 ) }, it ran with ${ meta.code.commit.slice( 0, 7 ) }` );
	}
	for ( const d of now.dirty ) {
		if ( d.mtime > meta.started ) {
			why.push( `${ d.file } (uncommitted) was touched after it ran` );
		}
	}
	return why;
}

// --------------------------------------------------- what a check reads

const readJson = ( f, fallback ) => ( fs.existsSync( f ) ? JSON.parse( fs.readFileSync( f, 'utf8' ) ) : fallback );

/**
 * What a scenario left, for its checks. THROWS if it is missing, if a step
 * failed when it ran, or if it is older than the code (staleness()). Returns
 * { meta, setup, units: [ { width, captures: { label: { page, boxes, values,
 * console, network, cookies, blocks, html() } } } ], get( width, label ) }.
 */
export function artifacts( id ) {
	const dir = path.join( OUT_DIR, id );
	const meta = readJson( path.join( dir, 'scenario.json' ), null );
	if ( ! meta ) {
		throw new Error( `Nothing saved of the scenario ${ id } (${ dir }).\n  It runs with: node tests/browser.mjs ${ id.slice( 0, 2 ) } (with a WordPress up: node tests/run.mjs --serve)` );
	}
	const failed = meta.units.filter( ( u ) => u.error );
	if ( failed.length ) {
		throw new Error( `The scenario ${ id } failed when it ran (${ meta.base_url }):\n${ failed.map( ( u ) => `  ${ u.width ? '@' + u.width + ': ' : '' }${ u.error }` ).join( '\n' ) }` );
	}
	const stale = staleness( meta );
	if ( stale.length ) {
		throw new Error( `What was saved of the scenario ${ id } is older than the code:\n  ${ stale.join( '\n  ' ) }\n  It runs again with: node tests/browser.mjs ${ id.slice( 0, 2 ) }` );
	}
	const units = meta.units.map( ( u ) => {
		const captures = {};
		for ( const c of u.captures ) {
			const cdir = path.join( dir, c.dir );
			captures[ c.label ] = {
				dir: cdir,
				page: readJson( path.join( cdir, 'page.json' ), {} ),
				boxes: readJson( path.join( cdir, 'boxes.json' ), {} ),
				values: readJson( path.join( cdir, 'values.json' ), {} ),
				console: readJson( path.join( cdir, 'console.json' ), null ),
				network: readJson( path.join( cdir, 'network.json' ), null ),
				cookies: readJson( path.join( cdir, 'cookies.json' ), null ),
				blocks: readJson( path.join( cdir, 'blocks.json' ), null ),
				html: () => fs.readFileSync( path.join( cdir, 'html.html' ), 'utf8' ),
				aria: () => fs.readFileSync( path.join( cdir, 'aria.yml' ), 'utf8' ),
			};
		}
		return { ...u, captures };
	} );
	const get = ( width, label ) => {
		const unit = units.find( ( u ) => ! width || u.width === width );
		if ( ! unit || ! unit.captures[ label ] ) {
			throw new Error( `${ id }: no capture «${ label }»${ width ? ' at ' + width : '' }` );
		}
		return unit.captures[ label ];
	};
	return { meta, setup: readJson( path.join( dir, 'setup.json' ), {} ), units, get, base: new URL( meta.base_url ) };
}

/** The page errors and console errors of a capture's console.json, minus those matching `ignore` (a regex). */
export function errorsOf( capture, { ignore = null, pageOnly = false } = {} ) {
	return ( capture.console || [] )
		.filter( ( m ) => 'pageerror' === m.type || ( ! pageOnly && 'error' === m.type ) )
		.map( ( m ) => `${ m.type }: ${ m.text }${ m.url ? ' @ ' + m.url : '' }` )
		.filter( ( t ) => ! ignore || ! ignore.test( t ) );
}

// ------------------------------------------------------------ the runner

/** Playwright locator for one Recorder selector (string, or list = chain). */
function locatorFor( scope, selector ) {
	const parts = Array.isArray( selector ) ? selector : [ selector ];
	let loc = scope;
	for ( const part of parts ) {
		if ( part.startsWith( 'aria/' ) ) {
			const m = part.slice( 5 ).match( /^(.*?)(?:\[role="([^"]+)"\])?$/ );
			const [ name, role ] = [ m[ 1 ], m[ 2 ] ];
			loc = role ? loc.getByRole( role, name ? { name, exact: true } : {} ) : loc.getByText( name, { exact: true } ).or( loc.getByLabel( name, { exact: true } ) );
		} else if ( part.startsWith( 'xpath/' ) ) {
			loc = loc.locator( `xpath=${ part.slice( 6 ) }` );
		} else if ( part.startsWith( 'pierce/' ) ) {
			loc = loc.locator( part.slice( 7 ) );
		} else if ( part.startsWith( 'text/' ) ) {
			loc = loc.getByText( part.slice( 5 ) );
		} else {
			loc = loc.locator( part );
		}
	}
	return loc;
}

/** The frame of a step (Recorder «frame»: child indexes from the main frame). */
function frameOf( page, step ) {
	let frame = page.mainFrame();
	for ( const i of step.frame || [] ) {
		frame = frame.childFrames()[ i ];
		if ( ! frame ) {
			throw new Error( `there is no frame ${ JSON.stringify( step.frame ) }` );
		}
	}
	return frame;
}

/** First selector that finds something, in order; waits for that FACT up to timeout. */
async function resolve( page, step, selectors, timeout, optional = false ) {
	const scope = frameOf( page, step );
	const candidates = selectors.map( ( s ) => locatorFor( scope, s ) );
	const any = candidates.reduce( ( a, b ) => a.or( b ) );
	// Optional: what there is NOW (the page has loaded); it is not waited for.
	if ( optional ) {
		for ( const c of candidates ) {
			if ( await c.count() ) {
				return c.first();
			}
		}
		return null;
	}
	try {
		await any.first().waitFor( { state: 'attached', timeout } );
	} catch {
		throw new Error( `no selector found anything in ${ timeout } ms: ${ JSON.stringify( selectors ) }` );
	}
	for ( const c of candidates ) {
		if ( await c.count() ) {
			return c.first();
		}
	}
	return any.first();
}

/** Absolute URL of a navigate step, on the base (see «the trap» in the header). */
function urlOf( raw, base ) {
	const u = new URL( raw, base );
	if ( LOCAL_HOSTS.includes( u.hostname ) ) {
		const b = new URL( base );
		u.protocol = b.protocol;
		u.host = b.host;
	}
	return u.href;
}

/** A value of the setup answers by its dotted path («draft.0.json.id»). */
function lookup( vars, ref ) {
	if ( 'now' === ref ) {
		return String( Math.floor( Date.now() / 1000 ) );
	}
	let v = vars;
	for ( const k of ref.split( '.' ) ) {
		v = v?.[ k ];
	}
	if ( v === undefined ) {
		throw new Error( `«{{@${ ref }}}»: the setup answered nothing there` );
	}
	return 'string' === typeof v ? v : JSON.stringify( v );
}
/** A step with its «{{…}}» filled in (every string, at any depth). */
function fill( value, vars ) {
	if ( 'string' === typeof value ) {
		return value.replace( PLACEHOLDER, ( _, ref ) => lookup( vars, ref.trim() ) );
	}
	if ( Array.isArray( value ) ) {
		return value.map( ( v ) => fill( v, vars ) );
	}
	if ( value && 'object' === typeof value ) {
		return Object.fromEntries( Object.entries( value ).map( ( [ k, v ] ) => [ k, fill( v, vars ) ] ) );
	}
	return value;
}

/**
 * In-flight requests of a page that keep it from being «ready». settle: the
 * capture's list of URL regexes that count, of ANY type (images too: «every
 * photo request has finished» is a fact a capture can ask for); absent =
 * every request of a BLOCKING_TYPES type; [] = none.
 */
function trackRequests( page ) {
	const flying = new Set();
	let waiters = [];
	const matching = ( settle ) => [ ...flying ].filter( ( r ) => ( settle ? settle.some( ( re ) => re.test( r.url() ) ) : BLOCKING_TYPES.includes( r.resourceType() ) ) );
	page.on( 'request', ( r ) => flying.add( r ) );
	const done = ( r ) => {
		flying.delete( r );
		waiters = waiters.filter( ( w ) => ( matching( w.settle ).length ? true : ( w.resolve(), false ) ) );
	};
	page.on( 'requestfinished', done );
	page.on( 'requestfailed', done );
	return {
		idle: ( settle ) => ( matching( settle ).length ? new Promise( ( resolve ) => waiters.push( { settle, resolve } ) ) : Promise.resolve() ),
		pending: ( settle ) => matching( settle ).map( ( r ) => r.url() ),
	};
}

/** The page-side facts of «ready»: loaded, fonts in, images touching the viewport complete. */
export const READY = () =>
	'complete' === document.readyState &&
	'loaded' === document.fonts.status &&
	[ ...document.images ].every( ( img ) => {
		const r = img.getBoundingClientRect();
		return img.complete || ! ( r.width && r.height && r.bottom > 0 && r.top < innerHeight && r.right > 0 && r.left < innerWidth );
	} );

/** Waits for the page to be «ready» (facts, see the header); fails naming what was missing. */
async function waitReady( page, requests, timeout, settle ) {
	const deadline = Date.now() + timeout;
	for ( ;; ) {
		const left = deadline - Date.now();
		try {
			await page.waitForFunction( READY, null, { timeout: Math.max( 1, left ), polling: 'raf' } );
			await Promise.race( [ requests.idle( settle ), new Promise( ( _, no ) => setTimeoutCeiling( no, deadline - Date.now() ) ) ] );
		} catch {
			const facts = await page.evaluate( () => ( { readyState: document.readyState, fonts: document.fonts.status, images: [ ...document.images ].filter( ( i ) => ! i.complete ).map( ( i ) => i.currentSrc || i.src ).slice( 0, 3 ) } ) ).catch( () => ( {} ) );
			throw new Error( `the page was not ready in ${ timeout } ms: ${ JSON.stringify( facts ) }; requests in flight: ${ requests.pending( settle ).map( ( u ) => u.slice( 0, 120 ) ).join( ', ' ) || 'none' }` );
		}
		// A finished request may have put images or asked for another: everything at once.
		if ( ( await page.evaluate( READY ) ) && ! requests.pending( settle ).length ) {
			return;
		}
	}
}

/** A failure CEILING (rejects when the time is up): never a delay to wait for something. */
function setTimeoutCeiling( reject, ms ) {
	const t = setTimeout( () => reject( new Error( 'ceiling' ) ), Math.max( 1, ms ) );
	t.unref?.();
}

/** Measures the boxes of a capture (runs in the page or in a frame). */
function measureBoxes( specs ) {
	const r2 = ( n ) => Math.round( n * 10 ) / 10;
	const rect = ( r ) => ( { x: r2( r.left ), y: r2( r.top ), w: r2( r.width ), h: r2( r.height ) } );
	const TEXT_TAGS = [ 'P', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ];
	const out = {};
	for ( const s of specs ) {
		let els = [ ...document.querySelectorAll( s.selector ) ];
		if ( s.first ) {
			els = els.slice( 0, 1 );
		}
		const withins = s.within ? [ ...document.querySelectorAll( s.within ) ] : null;
		out[ s.name ] = els
			.map( ( el ) => {
				const b = el.getBoundingClientRect();
				const rec = { text: ( el.innerText ?? el.textContent ?? '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 200 ), ...rect( b ), visible: el.checkVisibility ? el.checkVisibility( { visibilityProperty: true, opacityProperty: true } ) && b.width > 0 && b.height > 0 : b.width > 0 && b.height > 0 };
				if ( s.inViewport && ! ( b.bottom > 0 && b.top < innerHeight && b.right > 0 && b.left < innerWidth ) ) {
					return null;
				}
				if ( s.styles ) {
					const cs = getComputedStyle( el );
					rec.styles = Object.fromEntries( s.styles.map( ( p ) => [ p, cs[ p ] ] ) );
				}
				if ( s.pseudo ) {
					rec.pseudo = Object.fromEntries( Object.entries( s.pseudo ).map( ( [ ps, props ] ) => {
						const cs = getComputedStyle( el, ps );
						return [ ps, Object.fromEntries( props.map( ( p ) => [ p, cs[ p ] ] ) ) ];
					} ) );
				}
				if ( s.attrs ) {
					rec.attrs = Object.fromEntries( s.attrs.map( ( a ) => [ a, el.getAttribute( a ) ] ) );
				}
				if ( s.props ) {
					rec.props = Object.fromEntries( s.props.map( ( p ) => [ p, el[ p ] ] ) );
				}
				if ( withins ) {
					rec.within = withins.indexOf( el.closest( s.within ) );
				}
				if ( s.ink ) {
					const inked = [ ...el.querySelectorAll( '*' ) ]
						.filter( ( e ) => e.matches( 'img, svg, iframe, input, select, textarea, video, .wp-block-button__link' ) || [ ...e.childNodes ].some( ( n ) => 3 === n.nodeType && n.textContent.trim() ) )
						.filter( ( e ) => 'hidden' !== getComputedStyle( e ).visibility )
						.map( ( e ) => e.getBoundingClientRect() )
						.filter( ( r ) => r.width > 0 && r.height > 0 );
					rec.ink = inked.length ? { top: r2( Math.min( ...inked.map( ( r ) => r.top ) ) ), bottom: r2( Math.max( ...inked.map( ( r ) => r.bottom ) ) ) } : null;
				}
				if ( s.lines ) {
					let rects = [ ...el.getClientRects() ];
					if ( TEXT_TAGS.includes( el.tagName ) ) {
						const range = document.createRange();
						range.selectNodeContents( el );
						rects = [ ...range.getClientRects() ];
					}
					rec.lines = rects.filter( ( r ) => r.width > 0 && r.height > 0 ).map( rect );
				}
				if ( s.background ) {
					let bg = null;
					for ( let n = el; n && ! bg; n = n.parentElement ) {
						const c = getComputedStyle( n ).backgroundColor.match( /[\d.]+/g ).map( Number );
						bg = 3 === c.length || 1 === c[ 3 ] ? c.slice( 0, 3 ) : null;
					}
					rec.background = bg || [ 255, 255, 255 ];
				}
				rec.tag = el.tagName.toLowerCase();
				return rec;
			} )
			.filter( Boolean );
	}
	return out;
}

/**
 * save «blocks» (runs in the block editor, wp.blocks loaded): every page and
 * synced pattern of the site (REST, content.raw) and the theme patterns whose
 * name starts with `prefix`, through wp.blocks.parse(). Returns { count,
 * invalid: [ { where, block, saved, expected } ], missing: [ { where, name } ] }.
 */
async function validateBlocks( prefix ) {
	const [ pages, synced, patterns ] = await Promise.all( [
		wp.apiFetch( { path: '/wp/v2/pages?per_page=100&context=edit&_fields=slug,content.raw' } ),
		wp.apiFetch( { path: '/wp/v2/blocks?per_page=100&context=edit&_fields=slug,content.raw' } ),
		wp.apiFetch( { path: '/wp/v2/block-patterns/patterns' } ),
	] );
	const sources = [
		...pages.map( ( p ) => [ `page /${ p.slug }/`, p.content.raw ] ),
		...synced.map( ( b ) => [ `synced pattern ${ b.slug }`, b.content.raw ] ),
		...( prefix ? patterns.filter( ( x ) => x.name.startsWith( prefix ) ).map( ( p ) => [ `pattern ${ p.name }`, p.content ] ) : [] ),
	];
	const out = { count: sources.length, invalid: [], missing: [] };
	const walk = ( blocks, where ) => {
		for ( const b of blocks ) {
			if ( ! b.isValid ) {
				let expected = '';
				try {
					expected = wp.blocks.getSaveContent( b.name, b.attributes, b.innerBlocks );
				} catch ( e ) {
					expected = `(${ e.message })`;
				}
				const saved = b.originalContent || '';
				let i = 0;
				while ( i < expected.length && expected[ i ] === saved[ i ] ) {
					i++;
				}
				out.invalid.push( { where, block: b.name, saved: saved.slice( Math.max( 0, i - 40 ), i + 80 ), expected: expected.slice( Math.max( 0, i - 40 ), i + 80 ) } );
			}
			if ( 'core/missing' === b.name ) {
				out.missing.push( { where, name: b.attributes.originalName } );
			}
			walk( b.innerBlocks || [], where );
		}
	};
	for ( const [ where, content ] of sources ) {
		walk( wp.blocks.parse( content ), where );
	}
	return out;
}

async function capture( { page, unit, params, requests, timeout, logs, net, cursor, response } ) {
	await waitReady( page, requests, timeout, params.settle && params.settle.map( ( s ) => new RegExp( s ) ) );
	const width = page.viewportSize().width;
	const rel = `${ params.label }@${ width }`;
	const dir = path.join( unit.outDir, rel );
	fs.mkdirSync( dir, { recursive: true } );
	const save = params.save || [];
	const info = await page.evaluate( () => ( {
		url: location.href,
		lang: document.documentElement.lang,
		title: document.title,
		innerWidth,
		innerHeight,
		scrollWidth: document.documentElement.scrollWidth,
		clientWidth: document.documentElement.clientWidth,
		scrollY,
		scrollHeight: document.documentElement.scrollHeight,
	} ) );
	fs.writeFileSync( path.join( dir, 'page.json' ), JSON.stringify( { ...info, status: response?.status ?? null }, null, 1 ) );
	if ( params.values ) {
		const values = {};
		for ( const [ name, expression ] of Object.entries( params.values ) ) {
			try {
				values[ name ] = await page.evaluate( expression );
			} catch ( error ) {
				throw new Error( `value «${ name }»: ${ error.message.split( '\n' )[ 0 ] }` );
			}
		}
		fs.writeFileSync( path.join( dir, 'values.json' ), JSON.stringify( values, null, 1 ) );
	}
	if ( save.includes( 'html' ) ) {
		fs.writeFileSync( path.join( dir, 'html.html' ), await page.content() );
	}
	if ( save.includes( 'console' ) ) {
		fs.writeFileSync( path.join( dir, 'console.json' ), JSON.stringify( logs, null, 1 ) );
	}
	if ( save.includes( 'network' ) ) {
		// The entries are written when their response is read: the ones already in flight are waited for (a fact).
		await Promise.all( net.pending );
		fs.writeFileSync( path.join( dir, 'network.json' ), JSON.stringify( net.list.slice( cursor.network ), null, 1 ) );
	}
	cursor.network = net.list.length;
	if ( save.includes( 'aria' ) ) {
		// The accessibility tree as Playwright reads it (YAML: role "name"): accessible names are not in the DOM.
		fs.writeFileSync( path.join( dir, 'aria.yml' ), await page.locator( 'body' ).ariaSnapshot( { timeout } ) );
	}
	if ( save.includes( 'cookies' ) ) {
		fs.writeFileSync( path.join( dir, 'cookies.json' ), JSON.stringify( await page.context().cookies(), null, 1 ) );
	}
	if ( save.includes( 'screenshot' ) ) {
		await page.screenshot( { path: path.join( dir, 'screenshot.png' ) } );
	}
	if ( save.includes( 'blocks' ) ) {
		fs.writeFileSync( path.join( dir, 'blocks.json' ), JSON.stringify( await page.evaluate( validateBlocks, params.patterns || '' ), null, 1 ) );
	}
	if ( params.boxes?.length ) {
		const byFrame = new Map();
		for ( const b of params.boxes ) {
			byFrame.set( b.frame || '', [ ...( byFrame.get( b.frame || '' ) || [] ), b ] );
		}
		const boxes = {};
		for ( const [ frameSel, specs ] of byFrame ) {
			const frame = frameSel ? await ( await page.locator( frameSel ).first().elementHandle( { timeout } ) ).contentFrame() : page.mainFrame();
			Object.assign( boxes, await frame.evaluate( measureBoxes, specs ) );
		}
		fs.writeFileSync( path.join( dir, 'boxes.json' ), JSON.stringify( boxes, null, 1 ) );
	}
	unit.captures.push( { label: params.label, dir: rel, width } );
}

/** Runs one unit (scenario × width) in a fresh context. */
async function runUnit( browser, scen, unit, base, vars ) {
	const flow = scen.flow;
	const started = Date.now();
	const firstViewport = flow.steps.find( ( s ) => 'setViewport' === s.type ) || {};
	const context = await browser.newContext( {
		viewport: { width: unit.width || firstViewport.width || 1280, height: firstViewport.height || 900 },
		deviceScaleFactor: firstViewport.deviceScaleFactor || 1,
		isMobile: Boolean( firstViewport.isMobile ),
		hasTouch: Boolean( firstViewport.hasTouch ),
		...( flow.emulate || {} ),
	} );
	for ( const s of flow.initScripts || [] ) {
		await context.addInitScript( { path: fileOf( s ) } );
	}
	const page = await context.newPage();
	if ( flow.dialogs ) {
		page.on( 'dialog', ( d ) => ( 'accept' === flow.dialogs ? d.accept() : d.dismiss() ) );
	}
	const logs = [];
	// net.list: what finished; net.pending: the responses being read (a capture waits for them).
	const net = { list: [], pending: new Set(), waiters: [] };
	const outcome = new Map();
	const rewritten = new Map();
	// Chromium's initial priority of each request (CDP), matched by URL in order.
	const priorities = [];
	const cdp = await context.newCDPSession( page );
	await cdp.send( 'Network.enable' );
	cdp.on( 'Network.requestWillBeSent', ( e ) => priorities.push( { url: e.request.url, priority: e.request.initialPriority, used: false } ) );
	const priorityOf = ( url ) => {
		const p = priorities.find( ( x ) => x.url === url && ! x.used );
		if ( p ) {
			p.used = true;
		}
		return p?.priority ?? null;
	};
	const bodies = ( flow.network?.bodies || [] ).map( ( b ) => new RegExp( b ) );
	const record = ( entry ) => {
		net.list.push( entry );
		net.waiters = net.waiters.filter( ( w ) => ( w.check() ? ( w.resolve(), false ) : true ) );
	};
	page.on( 'console', ( m ) => logs.push( { type: m.type(), text: m.text().slice( 0, 500 ), url: m.location().url } ) );
	page.on( 'pageerror', ( e ) => logs.push( { type: 'pageerror', text: e.message.slice( 0, 500 ) } ) );
	page.on( 'requestfinished', ( r ) => {
		const reading = ( async () => {
			const res = await r.response().catch( () => null );
			const entry = { url: r.url(), method: r.method(), type: r.resourceType(), outcome: outcome.get( r ) || 'ok', status: res?.status() ?? null, priority: priorityOf( r.url() ) };
			if ( 'GET' !== r.method() ) {
				entry.body = ( r.postData() || '' ).slice( 0, 4000 );
			}
			if ( rewritten.has( r ) ) {
				entry.rewrite = rewritten.get( r );
			}
			if ( res && bodies.some( ( re ) => re.test( r.url() ) ) ) {
				const text = await res.text().catch( () => '' );
				try {
					entry.response = JSON.parse( text );
				} catch {
					entry.response = text.slice( 0, 4000 );
				}
			}
			record( entry );
		} )();
		net.pending.add( reading );
		reading.finally( () => net.pending.delete( reading ) );
	} );
	page.on( 'requestfailed', ( r ) => record( { url: r.url(), method: r.method(), type: r.resourceType(), outcome: outcome.get( r ) || 'failed', error: r.failure()?.errorText, priority: priorityOf( r.url() ) } ) );
	const requests = trackRequests( page );
	if ( flow.network ) {
		const own = new URL( base ).host;
		const rules = ( flow.network.fulfill || [] ).map( ( f ) => ( { ...f, re: new RegExp( f.url ) } ) );
		const rewrites = ( flow.network.rewrite || [] ).map( ( f ) => ( { ...f, re: new RegExp( f.url ) } ) );
		await context.route( '**/*', async ( route ) => {
			const req = route.request();
			const url = new URL( req.url() );
			const rule = rules.find( ( f ) => f.re.test( req.url() ) );
			if ( rule ) {
				outcome.set( req, 'simulated' );
				return route.fulfill( { status: rule.status || 200, contentType: rule.contentType, body: rule.body ? fs.readFileSync( fileOf( rule.body ) ) : '' } );
			}
			const rewrite = 'document' === req.resourceType() && rewrites.find( ( f ) => f.re.test( req.url() ) );
			if ( rewrite ) {
				const response = await route.fetch();
				const text = await response.text();
				outcome.set( req, 'rewritten' );
				rewritten.set( req, { find: rewrite.find, found: text.includes( rewrite.find ) } );
				return route.fulfill( { response, body: text.split( rewrite.find ).join( rewrite.replace ) } );
			}
			if ( 'abort' === flow.network.foreign && [ 'http:', 'https:' ].includes( url.protocol ) && url.host !== own ) {
				outcome.set( req, 'aborted' );
				return route.abort();
			}
			return route.continue();
		} );
	}
	const cursor = { network: 0 };
	let response = null;
	const timeoutOf = ( step, fallback = STEP_TIMEOUT ) => step.timeout || flow.timeout || fallback;
	try {
		unit.steps = [];
		for ( const [ i, raw ] of flow.steps.entries() ) {
			if ( 'customStep' === raw.type && 'setup' === raw.name ) {
				unit.steps.push( 0 ); // Done once for the scenario (runScenarios()).
				continue;
			}
			const t = Date.now();
			try {
				const step = fill( raw, vars );
				await runStep( step, timeoutOf( step ) );
				unit.steps.push( Number( ( ( Date.now() - t ) / 1000 ).toFixed( 2 ) ) );
			} catch ( error ) {
				throw new Error( `step ${ i + 1 } (${ raw.type }${ raw.name ? ' ' + raw.name : '' }): ${ error.message.split( '\n' )[ 0 ] }` );
			}
			if ( 'close' === raw.type ) {
				break;
			}
		}
	} catch ( error ) {
		unit.error = error.message;
	} finally {
		unit.seconds = ( Date.now() - started ) / 1000;
		await context.close();
	}

	async function runStep( step, timeout ) {
		const scope = () => frameOf( page, step );
		const navigation = ( step.assertedEvents || [] ).find( ( e ) => 'navigation' === e.type );
		const waitNav = navigation ? page.waitForEvent( 'load', { timeout: timeoutOf( step, NAVIGATE_TIMEOUT ) } ) : null;
		switch ( step.type ) {
			case 'setViewport':
				await page.setViewportSize( { width: unit.width || step.width, height: step.height } );
				break;
			case 'navigate': {
				const r = await page.goto( urlOf( step.url, base ), { waitUntil: 'load', timeout: timeoutOf( step, NAVIGATE_TIMEOUT ) } );
				response = r ? { status: r.status(), url: r.url() } : null;
				break;
			}
			case 'click':
			case 'doubleClick': {
				const loc = await resolve( page, step, step.selectors, timeout );
				const box = await loc.boundingBox( { timeout } );
				const position = box ? { x: Math.max( 0, Math.min( step.offsetX, box.width - 1 ) ), y: Math.max( 0, Math.min( step.offsetY, box.height - 1 ) ) } : undefined;
				const button = { auxiliary: 'middle', secondary: 'right' }[ step.button ] || 'left';
				await loc[ 'click' === step.type ? 'click' : 'dblclick' ]( { position, button, timeout } );
				break;
			}
			case 'hover':
				await ( await resolve( page, step, step.selectors, timeout ) ).hover( { timeout } );
				break;
			case 'change': {
				const loc = await resolve( page, step, step.selectors, timeout );
				if ( 'select' === ( await loc.evaluate( ( e ) => e.tagName.toLowerCase() ) ) ) {
					await loc.selectOption( step.value, { timeout } );
				} else {
					await loc.fill( step.value, { timeout } );
				}
				break;
			}
			case 'keyDown':
				await page.keyboard.down( step.key );
				break;
			case 'keyUp':
				await page.keyboard.up( step.key );
				break;
			case 'scroll':
				if ( step.selectors ) {
					await ( await resolve( page, step, step.selectors, timeout ) ).evaluate( ( e, [ x, y ] ) => e.scrollTo( { left: x, top: y, behavior: 'instant' } ), [ step.x || 0, step.y || 0 ] );
				} else {
					await scope().evaluate( ( [ x, y ] ) => scrollTo( { left: x, top: y, behavior: 'instant' } ), [ step.x || 0, step.y || 0 ] );
				}
				break;
			case 'waitForElement': {
				const op = step.operator || '>=';
				const count = step.count ?? 1;
				const candidates = step.selectors.map( ( s ) => locatorFor( scope(), s ) );
				const fact = `${ JSON.stringify( step.selectors ) } ${ op } ${ count }${ undefined === step.visible ? '' : step.visible ? ' visible' : ' hidden' }${ step.attributes ? ' with ' + JSON.stringify( step.attributes ) : '' }`;
				const handles = async () => {
					for ( const c of candidates ) {
						if ( await c.count() ) {
							return c.elementHandles();
						}
					}
					return [];
				};
				const holds = async () => {
					let n = 0;
					for ( const h of await handles() ) {
						const ok = await h.evaluate( ( e, s ) => {
							if ( undefined !== s.visible && ( e.checkVisibility() && e.getBoundingClientRect().width > 0 ) !== s.visible ) {
								return false;
							}
							for ( const [ k, v ] of Object.entries( s.attributes || {} ) ) {
								if ( e.getAttribute( k ) !== v ) {
									return false;
								}
							}
							for ( const [ k, v ] of Object.entries( s.properties || {} ) ) {
								if ( JSON.stringify( e[ k ] ) !== JSON.stringify( v ) ) {
									return false;
								}
							}
							return true;
						}, { visible: step.visible, attributes: step.attributes, properties: step.properties } ).catch( () => false );
						n += ok ? 1 : 0;
					}
					return '==' === op ? n === count : '<=' === op ? n <= count : n >= count;
				};
				// The fact is looked at again on every change of the page's DOM (MutationObserver), not every X ms.
				const deadline = Date.now() + timeout;
				while ( ! ( await holds() ) ) {
					if ( Date.now() >= deadline ) {
						throw new Error( `did not hold in ${ timeout } ms: ${ fact }` );
					}
					await scope()
						.evaluate( ( ms ) => new Promise( ( done ) => {
							const o = new MutationObserver( () => {
								o.disconnect();
								done();
							} );
							o.observe( document, { subtree: true, childList: true, attributes: true } );
							// Ceiling: if nothing changes, it comes back to look at the deadline.
							const t = setTimeout( () => {
								o.disconnect();
								done();
							}, ms );
							t.unref?.();
						} ), Math.max( 1, deadline - Date.now() ) )
						.catch( () => {} );
				}
				break;
			}
			case 'waitForExpression':
				try {
					await scope().waitForFunction( step.expression, null, { timeout, polling: 'raf' } );
				} catch ( error ) {
					throw new Error( `did not hold in ${ timeout } ms: ${ step.expression }${ /Timeout/.test( error.message ) ? '' : ` (${ error.message.split( '\n' )[ 0 ] })` }` );
				}
				break;
			case 'close':
				break;
			case 'customStep':
				await runCustom( step, timeout, scope );
				break;
			default:
				throw new Error( `unknown step type «${ step.type }»` );
		}
		if ( waitNav ) {
			await waitNav;
		}
	}

	async function runCustom( step, timeout, scope ) {
		const params = step.parameters;
		switch ( step.name ) {
			case 'capture':
				await capture( { page, unit, params, requests, timeout, logs, net, cursor, response } );
				break;
			case 'scrollIntoView': {
				const loc = await resolve( page, step, params.selectors, timeout, params.optional );
				// Instant: scroll-behavior: smooth on the root would animate (see the header).
				await loc?.evaluate( ( e ) => e.scrollIntoView( { block: 'center', behavior: 'instant' } ) );
				break;
			}
			case 'run':
				await scope().evaluate( params.expression );
				break;
			case 'request': {
				const re = new RegExp( params.url );
				const count = params.count ?? 1;
				const matches = () => net.list.filter( ( e ) => re.test( e.url ) && ( ! params.method || e.method === params.method ) && ( ! params.status || e.status === params.status ) ).length;
				const fact = `${ count } finished request(s) to /${ params.url }/${ params.method ? ' ' + params.method : '' }${ params.status ? ' answered ' + params.status : '' }`;
				if ( matches() < count ) {
					await new Promise( ( resolveFact, reject ) => {
						net.waiters.push( { check: () => matches() >= count, resolve: resolveFact } );
						setTimeoutCeiling( () => reject( new Error( `did not hold in ${ timeout } ms: ${ fact } (there are ${ matches() })` ) ), timeout );
					} );
				}
				break;
			}
			case 'touch': {
				// A real finger (carousel, 03-10-2026): touchStart, one move per frame, STILL for six
				// frames, touchEnd. Input.synthesizeScrollGesture moves nothing in windowless Chromium;
				// moves without a frame between them scroll but do not finish the gesture; a finger that
				// lifts while moving starts a fling that never ends.
				const loc = await resolve( page, step, params.selectors, timeout );
				const box = await loc.boundingBox( { timeout } );
				const x0 = Math.round( params.dx > 0 ? box.x + 30 : box.x + box.width - 30 );
				const y0 = Math.round( box.y + ( params.y ?? box.height / 2 ) );
				const frame = () => page.evaluate( () => new Promise( ( r ) => requestAnimationFrame( r ) ) );
				await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [ { x: x0, y: y0 } ] } );
				for ( let i = 1; i <= 18; i++ ) {
					await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [ { x: x0 + ( params.dx * Math.min( i, 12 ) ) / 12, y: y0 } ] } );
					await frame();
				}
				await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] } );
				break;
			}
			case 'drag': {
				const from = await resolve( page, step, params.selectors, timeout );
				const to = await resolve( page, step, params.to.selectors, timeout );
				const box = await to.boundingBox( { timeout } );
				const at = ( v, size ) => ( v < 0 ? size + v : v );
				await from.dragTo( to, { targetPosition: { x: at( params.to.x ?? 10, box.width ), y: at( params.to.y ?? 10, box.height ) }, timeout } );
				break;
			}
			case 'cookies':
				await page.context().addCookies( params.set.map( ( c ) => ( { name: c.name, value: c.value, url: new URL( c.path || '/', base ).href } ) ) );
				break;
			default:
				throw new Error( `unknown customStep «${ step.name }»` );
		}
	}
}

/** The setup of a scenario (its leading customStep «setup»): every request in order, from Node. Returns the «as» answers. */
async function runSetup( scen, base, outDir ) {
	const vars = {};
	const answers = [];
	for ( const step of scen.flow.steps.filter( ( s ) => 'customStep' === s.type && 'setup' === s.name ) ) {
		for ( const raw of step.parameters.requests ) {
			const r = fill( raw, vars );
			const method = r.method || ( r.body === undefined ? 'GET' : 'POST' );
			const res = await fetch( base + '/wp-json' + r.path, { method, headers: r.body === undefined ? {} : { 'Content-Type': 'application/json' }, body: r.body === undefined ? undefined : JSON.stringify( r.body ) } );
			const text = await res.text();
			let json = null;
			try {
				json = JSON.parse( text );
			} catch {}
			answers.push( { path: r.path, method, status: res.status, json } );
			if ( ! res.ok ) {
				throw new Error( `setup ${ method } ${ r.path } -> ${ res.status } ${ text.slice( 0, 300 ) }` );
			}
			if ( r.as ) {
				vars[ r.as ] = json;
			}
		}
	}
	fs.writeFileSync( path.join( outDir, 'setup.json' ), JSON.stringify( { vars, answers }, null, 1 ) );
	return vars;
}

/** Picks scenarios from CLI words: numbers, «module/», or name fragments; none = all. */
export function pick( words, all = listScenarios() ) {
	if ( ! words.length ) {
		return all;
	}
	const chosen = [];
	for ( const w of words ) {
		const found = /^\d+$/.test( w ) ? all.filter( ( s ) => s.number === Number( w ) ) : w.endsWith( '/' ) ? all.filter( ( s ) => `${ s.module }/` === w ) : all.filter( ( s ) => s.id.includes( w ) || `${ s.module }/${ s.name }`.startsWith( w ) );
		if ( ! found.length ) {
			throw new Error( `no scenario «${ w }» (there are: ${ all.map( ( s ) => s.id ).join( ', ' ) })` );
		}
		found.forEach( ( s ) => chosen.includes( s ) || chosen.push( s ) );
	}
	return chosen;
}

/** Every check file of the repo (browser-*.test.mjs in tests/ and modules/<m>/tests/), relative and with «/». */
export function checkFiles() {
	return [ [ 'tests' ], ...fs.readdirSync( path.join( ROOT, 'modules' ) ).map( ( m ) => [ 'modules', m, 'tests' ] ) ]
		.map( ( parts ) => parts.join( '/' ) )
		.filter( ( d ) => fs.existsSync( path.join( ROOT, d ) ) )
		.flatMap( ( d ) => fs.readdirSync( path.join( ROOT, d ) ).filter( ( f ) => /^browser-.*\.test\.mjs$/.test( f ) ).map( ( f ) => `${ d }/${ f }` ) );
}
/** The scenario a check reads (its first line «… browser scenario «id» …»), or null. */
export const scenarioOfCheck = ( rel ) => ( fs.readFileSync( path.join( ROOT, rel ), 'utf8' ).split( '\n' )[ 0 ].match( /browser scenario «([^»]+)»/ ) || [] )[ 1 ] || null;
/** The check files that read a scenario. */
export const checksOf = ( id ) => checkFiles().filter( ( f ) => scenarioOfCheck( f ) === id );

/**
 * Runs scenarios against base, ONE browser for all of them; each scenario's
 * setup, then its units. Returns { ok, seconds, launch, results: [ { id, ok, seconds, error? } ] }.
 */
export async function runScenarios( { scenarios, base, log = console.log } ) {
	const host = new URL( base ).hostname;
	const local = LOCAL_HOSTS.includes( host );
	for ( const s of scenarios ) {
		const errors = validate( s.flow );
		if ( errors.length ) {
			throw new Error( `${ s.id }.json is not valid:\n  ${ errors.join( '\n  ' ) }` );
		}
		if ( s.flow.writes && ! local ) {
			throw new Error( `${ s.id } writes in WordPress (writes: true) and ${ host } is not local: it is not run` );
		}
	}
	const t0 = Date.now();
	const browser = await playwright().chromium.launch();
	const launch = ( Date.now() - t0 ) / 1000;
	const results = [];
	try {
		for ( const s of scenarios ) {
			const started = Date.now();
			const code = codeState();
			const outDir = path.join( OUT_DIR, s.id );
			fs.rmSync( outDir, { recursive: true, force: true } );
			fs.mkdirSync( outDir, { recursive: true } );
			const units = ( s.flow.widths || [ null ] ).map( ( w ) => ( { width: w, outDir, captures: [] } ) );
			let setupSeconds = 0;
			try {
				const vars = await runSetup( s, base, outDir );
				setupSeconds = ( Date.now() - started ) / 1000;
				const queue = [ ...units ];
				await Promise.all(
					Array.from( { length: Math.min( TABS, units.length ) }, async () => {
						while ( queue.length ) {
							await runUnit( browser, s, queue.shift(), base, vars );
						}
					} )
				);
			} catch ( error ) {
				units.forEach( ( u ) => ( u.error ||= error.message.split( '\n' )[ 0 ] ) );
			}
			const seconds = ( Date.now() - started ) / 1000;
			const meta = {
				id: s.id,
				title: s.flow.title,
				sha: sha( s.text ),
				base_url: base,
				started,
				ran: new Date( started ).toISOString(),
				code,
				seconds,
				setup: setupSeconds,
				units: units.map( ( { outDir: _, ...u } ) => u ),
			};
			fs.writeFileSync( path.join( outDir, 'scenario.json' ), JSON.stringify( meta, null, 1 ) );
			const failed = units.filter( ( u ) => u.error );
			log( `${ failed.length ? '✖' : '✔' } ${ s.module ? s.module + '/' : '' }${ s.id }: ${ seconds.toFixed( 1 ) } s (setup ${ setupSeconds.toFixed( 1 ) } s${ units.length > 1 ? `, ${ units.length } units` : '' })${ failed.length ? '\n' + failed.map( ( u ) => `    ${ u.width ? '@' + u.width + ': ' : '' }${ u.error }` ).join( '\n' ) : '' }` );
			results.push( { id: s.id, ok: ! failed.length, seconds, error: failed[ 0 ]?.error } );
		}
	} finally {
		await browser.close();
	}
	const seconds = ( Date.now() - t0 ) / 1000;
	return { ok: results.every( ( r ) => r.ok ), seconds, launch, results };
}

// ------------------------------------------------------------------- CLI
if ( process.argv[ 1 ] && fileURLToPath( import.meta.url ) === path.resolve( process.argv[ 1 ] ) ) {
	const args = process.argv.slice( 2 );
	const baseAt = args.indexOf( '--base' );
	const reportAt = args.indexOf( '--report' );
	const valueAt = new Set( [ baseAt, reportAt ].filter( ( i ) => i >= 0 ).map( ( i ) => i + 1 ) );
	const words = args.filter( ( a, i ) => ! a.startsWith( '--' ) && ! valueAt.has( i ) );
	const all = listScenarios();
	if ( args.includes( '--list' ) ) {
		for ( const s of all ) {
			console.log( `${ String( s.number ).padStart( 2 ) }  ${ `${ s.module }/${ s.name }`.padEnd( 44 ) } ${ s.flow.steps.length } steps — ${ s.flow.title }` );
		}
		process.exit( 0 );
	}
	const base = ( baseAt >= 0 ? args[ baseAt + 1 ] : process.env.AS_BASE || DEFAULT_BASE ).replace( /\/$/, '' );
	let code = 1;
	try {
		const chosen = pick( words, all );
		const up = await fetch( base + '/wp-json/estrategia-web-site-test/v1/ping' ).then( ( r ) => r.ok, () => false );
		if ( ! up ) {
			throw new Error( `No test WordPress answers on ${ base }: start one with «node tests/run.mjs --serve» (or pass --base).` );
		}
		const result = await runScenarios( { scenarios: chosen, base } );
		if ( reportAt >= 0 ) {
			fs.writeFileSync( args[ reportAt + 1 ], JSON.stringify( result, null, 1 ) );
		}
		code = result.ok ? 0 : 1;
		console.log( `browser: ${ result.seconds.toFixed( 1 ) } s in all (launching Chromium ${ result.launch.toFixed( 1 ) } s)` );
		if ( args.includes( '--check' ) ) {
			const checks = result.results.flatMap( ( r ) => checksOf( r.id ) );
			const c = spawnSync( process.execPath, [ '--test', ...checks ], { cwd: ROOT, encoding: 'utf8', env: { ...process.env, EW_BROWSER_OUT: OUT_DIR } } );
			console.log( ( c.stdout + c.stderr ).trim() );
			code = 0 === c.status ? code : 1;
		}
	} catch ( error ) {
		console.error( error.message );
	}
	process.exitCode = code;
}
