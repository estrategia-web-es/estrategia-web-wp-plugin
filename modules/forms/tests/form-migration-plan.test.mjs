// NIVEL: 1 — no WordPress: the PLAN of the forms migrator (which form trees there are in a content, how they group into form posts, which are translations, the replacement) is pure PHP (Form_Migration::find_trees, plan, replace) and runs with the machine's PHP. What WordPress and WPML do with the plan is level 2 (form-migration*.test.mjs).
//
// If there is no `php` in the PATH, the test FAILS (it is not skipped).
// Seen red (27-10-2026): with the copies compared byte for byte instead of
// normalize(), «the same Contacto copied with other blank lines» came out
// as DIFFERENT; with «form» not required to be followed by a space, the
// form-ref block was taken as a form opener with no closer.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "migration-plan.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr));
const out = JSON.parse(r.stdout);

test("find_trees: the form trees of a content, inside groups too, with their exact bytes; form-ref is not a form", () => {
  assert.deepEqual(out.find.map((f) => f[0]), ["contacto", "vacio", "viejo"], "three forms: in a group, self-closing and the pre-1.0.0 name");
  assert.equal(out.find[0][1], "Contacto } raro", "a «}» inside an attribute does not cut the opener");
  assert.ok(out.find[0][2] && out.find[1][2], "start/length point to the tree as it is in the content");
  assert.deepEqual(out.find_errors, [], "the reference block is not taken for a form opener");
  assert.doesNotMatch(out.legacy, /armazon-forms/, "a pre-1.0.0 tree moves with today's names");
  assert.match(out.unclosed[0], /opener with no closer/);
});

test("plan: one form post per identifier AND language; never unified across identifiers", () => {
  assert.deepEqual(out.keys, ["contacto|es", "contacto|en", "presupuesto|es", "presupuesto|en", "#70-0|es", "#70-1|es", "a|es", "b|es"]);
  assert.equal(out.forms["contacto|es"].source, 10, "the first post by id gives the content");
  assert.ok(out.forms["a|es"] && out.forms["b|es"], "two identifiers with the same fields are two forms («sin unificar»)");
  assert.equal(out.forms["#70-0|es"].title, "Sin id", "no identifier and no title: the post's title");
  assert.equal(out.forms["#70-1|es"].title, "Otro");
  assert.deepEqual(out.posts["70"], ["#70-0|es", "#70-1|es"], "two forms without identifier in one page: two form posts");
});

test("plan: the same identifier copied in two posts of a language is ONE form, reported, and the copies compared", () => {
  assert.deepEqual(out.forms["contacto|es"].copies, [50]);
  assert.equal(out.forms["contacto|es"].identical, true, "only the blank lines between blocks changed");
  assert.deepEqual(out.posts["50"], ["contacto|es"], "the copy points to the same form post");
  assert.deepEqual(out.diff, [[60], false], "a copy with another label is flagged as different");
});

test("plan: translations by identifier, the default language's is the original; an existing form post is reused", () => {
  assert.equal(out.forms["contacto|en"].original, "contacto|es");
  assert.equal(out.forms["presupuesto|en"].original, "presupuesto|es");
  assert.equal(out.forms["contacto|es"].original, "");
  assert.equal(out.forms["presupuesto|es"].existing, 99, "a previous run's (or hand-made) form post with that identifier and language");
  assert.deepEqual(out.mono, [["contacto|"], ""], "without WPML: one bucket and no translations");
});

test("plan: a post with broken delimiters is left as it is, with a warning", () => {
  assert.equal(out.posts["90"], undefined);
  assert.match(out.errors[0], /^post 90: .*not migrated/);
});

test("replace: each tree becomes the reference block and nothing else changes", () => {
  assert.equal(
    out.replaced,
    '<!-- wp:paragraph --><p>Á</p><!-- /wp:paragraph -->\n<!-- wp:estrategia-web-forms/form-ref {"ref":101} /-->\n<p>medio</p>\n<!-- wp:estrategia-web-forms/form-ref {"ref":102} /-->\n<p>fin</p>'
  );
});
