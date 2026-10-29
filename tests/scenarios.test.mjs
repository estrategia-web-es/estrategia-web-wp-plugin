// NIVEL: 1 — reads files: every browser scenario (modules/<module>/tests/browser/NN-name.json) is a Recorder «user flow» our runner understands, its number is unique, and each one has a check that reads it (and every check reads one that exists).
/**
 * Before any browser opens (the scenarios run later in the battery, on the
 * shards' WordPresses: tests/run.mjs):
 * 1. each scenario passes validate() of tests/browser.mjs: @puppeteer/replay's
 *    UserFlow schema (Chrome DevTools → Recorder; step types and required
 *    fields as in Lavandería's copy, checked against its published schema,
 *    3.1.1, on 29-10-2026) plus our top keys and customSteps. A step type or
 *    customStep the runner does not know is an error, and so is a setup
 *    after another step or a «{{@name}}» no setup answered;
 * 2. its number NN is unique in the whole repo (the runner picks by number);
 * 3. it has at least one check (a browser-*.test.mjs whose first line says
 *    «browser scenario «NN-name»»): a scenario nobody reads loads pages for
 *    nothing;
 * 4. and the other way round: every check names a scenario that exists, and
 *    its first line declares level 1 (it reads files: it opens no browser).
 *
 * Seen red (29-10-2026) with a «tap» step (it does not exist) in
 * 42-whatsapp.json: «step 3 (tap): unknown step type»; and with a check's
 * first line naming «99-nope».
 * How to maintain it: a new Recorder step type the scenarios need goes into
 * STANDARD and runStep() of tests/browser.mjs at once; a customStep, into
 * CUSTOM and runCustom(). Its parameters' rules go into validate().
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { ROOT, listScenarios, validate, checksOf, checkFiles, scenarioOfCheck } from './browser.mjs';

const scenarios = listScenarios();

test( 'every scenario is valid, numbered once, and read by a check', () => {
	const bad = [];
	assert.ok( scenarios.length, 'there are no scenarios' );
	for ( const s of scenarios ) {
		const where = path.relative( ROOT, s.file ).replace( /\\/g, '/' );
		validate( s.flow ).forEach( ( e ) => bad.push( `${ where }: ${ e }` ) );
		if ( scenarios.filter( ( x ) => x.number === s.number ).length > 1 ) {
			bad.push( `${ where }: the number ${ s.number } is repeated` );
		}
		if ( ! checksOf( s.id ).length ) {
			bad.push( `${ where }: no browser-*.test.mjs reads it` );
		}
	}
	assert.deepEqual( bad, [], `\n${ bad.join( '\n' ) }` );
} );

test( 'every check reads a scenario that exists, and is level 1', () => {
	const ids = scenarios.map( ( s ) => s.id );
	const bad = [];
	for ( const f of checkFiles() ) {
		const first = fs.readFileSync( path.join( ROOT, f ), 'utf8' ).split( '\n' )[ 0 ];
		const read = scenarioOfCheck( f );
		if ( ! /^\/\/ NIVEL: 1 — /.test( first ) ) {
			bad.push( `${ f }: a check reads files: its first line is «// NIVEL: 1 — reads the browser scenario «NN-name»: …»` );
		}
		if ( ! read ) {
			bad.push( `${ f }: the first line does not say which scenario it reads («… browser scenario «NN-name»: …»)` );
		} else if ( ! ids.includes( read ) ) {
			bad.push( `${ f }: reads «${ read }», which does not exist` );
		}
	}
	assert.deepEqual( bad, [], `\n${ bad.join( '\n' ) }` );
} );
