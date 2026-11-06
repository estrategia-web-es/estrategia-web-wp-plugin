// NIVEL: 1 — reads files: how many expensive tests there are against the budget in tests/test-budget.json.
/**
 * DAVID'S RULE (06-11-2026, ARMAZON row 7, «Las pruebas caras tienen cupo»):
 * expensive tests have a budget that only goes down, and a browser is used
 * only if it is strictly necessary. «Me cansan las pruebas innecesarias y cada
 * proyecto tener que revisarlas porque en una semana se sube a 20 min de
 * prueba por despliegue.» Justifying the level and limiting each file's time
 * did not stop it: whoever writes a test also writes its justification, and
 * neither limits HOW MANY there are. A count does.
 *
 * WHAT IT COUNTS. server: files that declare level 2. browser: files that
 * declare level 3, plus the scenarios (modules/<module>/tests/browser/
 * NN-name.json). A level 1 check of a scenario counts for nothing: it reads
 * what the scenario saved. The budget counts files, not cases, on purpose: it
 * is the same on any machine and under any load, which a time is not.
 *
 * FAILS if the count is over the budget (the test does not enter: make it
 * level 1, taking the logic to a function that returns a value, or remove an
 * expensive test that no longer pays) or under it (lower the number in the same
 * commit, so that the room is not left for the next one).
 *
 * How to maintain it. Whoever adds or removes an expensive test, in that same
 * commit. Swapping one for another leaves the number as it is. Only David
 * raises it: the hook ~/.claude/hooks/check-cupo-pruebas.cjs denies an agent a
 * raise unless the same edit adds to «raises» an entry with the date, what it
 * is for and David's OK word for word:
 *     { "date": "2026-11-06", "for": "…", "ok": "<his literal words>" }
 * Someone without the hook (Juan) raises it in a pull request, where David
 * sees the number change.
 *
 * Edge case with a name: banners/admin-list has 7 cases and takes 89 s, and it
 * is ONE file. Adding an eighth case does not move this count; what stops a
 * file growing is its own time limit (the runner's, «// TIEMPO: n s»).
 * The trap: two branches that each remove a test both leave the number one
 * lower, and after merging both the count is under it: this test goes red on
 * the merge, and it is fixed by lowering the number again.
 *
 * Seen red (06-11-2026) with one level 2 file too many, with one scenario too
 * many, and with the number left high after removing a level 2 file.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { ROOT, listScenarios } from './browser.mjs';

const SKIP = new Set( [ '.cache', '.git', 'node_modules', 'vendor' ] );

function testFiles( dir ) {
	return fs.readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const full = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			return SKIP.has( entry.name ) ? [] : testFiles( full );
		}
		return entry.name.endsWith( '.test.mjs' ) ? [ full ] : [];
	} );
}

const levelOf = ( file ) => fs.readFileSync( file, 'utf8' ).match( /^\/\/ NIVEL: (\d)/ )?.[ 1 ];
const levels = [ 'tests', 'modules' ].flatMap( ( dir ) => testFiles( path.join( ROOT, dir ) ) ).map( levelOf );
const counted = {
	server: levels.filter( ( level ) => '2' === level ).length,
	browser: levels.filter( ( level ) => '3' === level ).length + listScenarios().length,
};
const budget = JSON.parse( fs.readFileSync( path.join( ROOT, 'tests/test-budget.json' ), 'utf8' ) );

for ( const kind of [ 'server', 'browser' ] ) {
	test( `the ${ kind } tests are exactly the budget`, () => {
		assert.ok( counted[ kind ] <= budget[ kind ], `${ counted[ kind ] } ${ kind } tests and the budget is ${ budget[ kind ] }. The new one does not enter: make it level 1, taking the logic to a function that returns a value, or remove an expensive test that no longer pays. Only David raises the budget (header of tests/test-budget.test.mjs).` );
		assert.ok( counted[ kind ] >= budget[ kind ], `${ counted[ kind ] } ${ kind } tests and the budget is still ${ budget[ kind ] }: lower it to ${ counted[ kind ] } in tests/test-budget.json, in this same commit.` );
	} );
}
