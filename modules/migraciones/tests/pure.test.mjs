// NIVEL: 1 — no browser or emulator. The migraciones module (includes/class-runner.php) with the machine's PHP and stubs: order, once each, a failure stops the queue, the lock.
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
//
// Seen red: with files() not sorted (the order test), with pending() ignoring
// the stored result (the «once» test), with the loop not breaking on failure
// (the failure test) and with lock() always true (the lock test).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const o = JSON.parse(r.stdout);

test("two migrations come in name order, and a file with another name is not one", () => {
  assert.deepEqual(o.orden, ["2026-11-26-primera", "2026-11-27-segunda"]);
});

test("a dry run lists them and writes nothing", () => {
  assert.deepEqual(o.seco.applied, ["2026-11-26-primera", "2026-11-27-segunda"]);
  assert.equal(o.nada_escrito, true);
});

test("applied in order, and only once", () => {
  assert.deepEqual(o.uno.applied, ["2026-11-26-primera", "2026-11-27-segunda"]);
  assert.deepEqual(o.otra_vez.applied, []);
  assert.deepEqual(o.hecho, { "2026-11-26-primera": ["ok", "uno"], "2026-11-27-segunda": ["ok", "dos"] });
});

test("a failing migration stops the queue; the rest stay pending", () => {
  assert.equal(o.falla.status, "error");
  assert.equal(o.falla.failed, "2026-11-27-segunda");
  assert.equal(o.falla.detail, "rota");
  assert.deepEqual(o.falla.applied, ["2026-11-26-primera"]);
  assert.deepEqual(o.falla_hecho, { "2026-11-26-primera": "ok", "2026-11-27-segunda": "error" });
  assert.deepEqual(o.falla_pendientes, ["2026-11-27-segunda", "2026-11-28-tercera"]);
  assert.ok(o.falla_acciones.includes("sg"));
});

test("a failure does not record the theme version; the next load retries only what failed, and it is logged", () => {
  assert.equal(o.ver_falla_status, "error");
  assert.equal(o.ver_falla_apuntada, null);
  assert.deepEqual(o.ver_falla_hecho, { "2026-11-26-primera": "ok", "2026-11-27-segunda": "error" });
  assert.ok(o.ver_falla_log.includes("estrategia_web_migracion:2026-11-27-segunda:error"));
  assert.deepEqual(o.ver_reintento, ["error", [], "2026-11-27-segunda"]);
  assert.equal(o.ver_reintento_apuntada, null);
  assert.deepEqual(o.ver_ok, ["ok", ["2026-11-27-segunda", "2026-11-28-tercera"], "1.1"]);
  assert.equal(o.ver_igual, null);
  assert.equal(o.ver_bloqueo_apuntada, null);
});

test("a held lock runs nothing; a stale one is replaced; the lock is always released", () => {
  assert.equal(o.bloqueada, "locked");
  assert.equal(o.caducada, "ok");
  assert.equal(o.lock_liberado, true);
});
