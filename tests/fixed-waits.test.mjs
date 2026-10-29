// NIVEL: 1 — reads files: the browser runner, the scenarios, their probes and their checks never wait a fixed time.
/**
 * DAVID'S RULE (29-10-2026, ARMAZON row 7): the browser NEVER waits a fixed
 * time. «Nunca sabemos si basta o no basta; casi siempre está
 * sobredimensionado. Las esperas tienen que ser de eventos.» It waits for
 * FACTS (an element, an expression that holds, the page ready, a request
 * finished: the header of tests/browser.mjs); a timeout is a failure ceiling,
 * not a wait.
 *
 * FAILS if, in CODE (comments are stripped first), any of these has:
 * - waitForTimeout (Playwright's pause);
 * - networkidle (Playwright's «network quiet»: 500 ms without requests, a
 *   fixed time in disguise);
 * - a promise that only waits for a setTimeout («new Promise( ( r ) =>
 *   setTimeout( r, … ) )»), also inside a scenario's run/values expression;
 * - a customStep named like a wait (wait, sleep, delay, pause, espera,
 *   pausa…): that would be the same thing through the side door.
 * Files: tests/browser.mjs, tests/browser-probes.js, every scenario and every
 * .js probe in modules/<module>/tests/browser/, every browser-*.test.mjs
 * check, and the helpers the checks import from their module (trips.mjs).
 *
 * Edge case with a name: a setTimeout that is a CEILING (it rejects or cuts
 * when the deadline of a fact passes: setTimeoutCeiling() and the
 * MutationObserver's in waitForElement, in tests/browser.mjs) is not a wait
 * and passes: it does not have the «new Promise( r => setTimeout( r» shape.
 * Another: modules/consent/tests/browser/timers.js WRAPS window.setTimeout to
 * count the page's own timers (that is how «the detector's scan ran» became a
 * fact): it calls the native one with the page's callback, never a promise.
 * The «request» customStep is a fact (a request finished), not a wait,
 * whatever its name: it is not called like one.
 *
 * Seen red (29-10-2026) with «await page.waitForTimeout( 100 );» in a check
 * and with a customStep «sleep» in 42-whatsapp.json.
 * How to maintain it: another way to pause found (another library, another
 * name) goes into FORBIDDEN, with its example here.
 * The trap: comments are stripped before looking, because the runner's
 * header EXPLAINS why networkidle is not used, and this one names all it forbids.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { ROOT, scenarioDirs, checkFiles } from './browser.mjs';

const FORBIDDEN = [
	[ /waitForTimeout/, 'waitForTimeout (a fixed pause)' ],
	[ /networkidle/, 'networkidle (500 ms without requests: a fixed time in disguise)' ],
	[ /new\s+Promise\s*\(\s*\(?\s*(\w+)\s*\)?\s*=>\s*\{?\s*(?:window\.)?setTimeout\s*\(\s*\1\b/, 'a promise that only waits for a setTimeout' ],
];
const WAIT_NAMES = /wait|sleep|delay|pause|espera|pausa|dormir/i;
const stripComments = ( code ) => code.replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /^\s*\/\/.*$/gm, '' );

test( 'no fixed waits in the browser runner, the scenarios, their probes and their checks', () => {
	const files = [
		path.join( ROOT, 'tests', 'browser.mjs' ),
		path.join( ROOT, 'tests', 'browser-probes.js' ),
		...scenarioDirs().flatMap( ( [ , dir ] ) => fs.readdirSync( dir ).filter( ( f ) => /\.(json|js)$/.test( f ) ).map( ( f ) => path.join( dir, f ) ) ),
		...checkFiles().map( ( f ) => path.join( ROOT, f ) ),
		path.join( ROOT, 'modules', 'carousel', 'tests', 'trips.mjs' ),
	];
	const bad = [];
	for ( const file of files ) {
		const rel = path.relative( ROOT, file ).replace( /\\/g, '/' );
		const text = fs.readFileSync( file, 'utf8' );
		const code = file.endsWith( '.json' ) ? text : stripComments( text );
		for ( const [ re, what ] of FORBIDDEN ) {
			if ( re.test( code ) ) {
				bad.push( `${ rel }: ${ what }` );
			}
		}
		if ( file.endsWith( '.json' ) ) {
			for ( const step of JSON.parse( text.replace( /^﻿/, '' ) ).steps || [] ) {
				if ( 'customStep' === step.type && WAIT_NAMES.test( step.name || '' ) ) {
					bad.push( `${ rel }: customStep «${ step.name }» named like a wait: wait for a fact (waitForElement, waitForExpression, request)` );
				}
			}
		}
	}
	assert.ok( files.length > 40, `only ${ files.length } files looked at` );
	assert.deepEqual( bad, [], `fixed waits in the browser:\n${ bad.join( '\n' ) }` );
} );
