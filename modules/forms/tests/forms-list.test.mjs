// NIVEL: 2 — Playground WordPress, because what is tested is what the «Forms → Forms» screen finds in a real database (pages, a synced pattern and the pages using it, drafts) and the Forms menu WordPress builds. Read with a cookie session (tests/lib.mjs adminSession): the screen is plain HTML.
//
// What and why: includes/admin/class-forms-list.php. With WPML (one row per
// form in the default language) it is not tested here: the test WordPress
// has no WPML; collapse() is pure and documented in the class.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { adminSession, reset, seedPage, wp } from "../../../tests/lib.mjs";

const PATTERN_FORM = `<!-- wp:group --><div class="wp-block-group"><!-- wp:estrategia-web-forms/form {"formId":"presupuesto","title":"Presupuesto"} -->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso al equipo","to":"equipo@ejemplo.test, {{email}}"} /-->
<!-- wp:estrategia-web-forms/notification {"to":"{{email}}"} /-->
<!-- wp:estrategia-web-forms/submit /-->
<!-- /wp:estrategia-web-forms/form --></div><!-- /wp:group -->`;

let s, pattern, user, draft, mention;
const created = [];

before(async () => {
  await reset();
  s = await adminSession();
  const p = await wp("/blocks", { method: "POST", body: { title: "Formulario de presupuesto", status: "publish", content: PATTERN_FORM } });
  assert.equal(p.status, 201, p.text);
  pattern = p.json.id;
  created.push(["/blocks", pattern]);
  user = await seedPage(`<!-- wp:paragraph --><p>Pide presupuesto</p><!-- /wp:paragraph --><!-- wp:block {"ref":${pattern}} /-->`, "pide-presupuesto");
  const d = await wp("/pages", { method: "POST", body: { title: "Contacto (borrador)", status: "draft", content: '<!-- wp:estrategia-web-forms/form {"formId":"contacto","title":"Contacto"} --><!-- wp:estrategia-web-forms/submit /--><!-- /wp:estrategia-web-forms/form -->' } });
  draft = d.json.id;
  created.push(["/pages", draft]);
  // Mentions the block name in TEXT: the LIKE finds it, parse_blocks() must not.
  const m = await wp("/pages", { method: "POST", body: { title: "Ayuda", status: "publish", content: "<!-- wp:paragraph --><p>Usa el bloque &lt;!-- wp:estrategia-web-forms/form --&gt;</p><!-- /wp:paragraph -->" } });
  mention = m.json.id;
  created.push(["/pages", mention]);
});
after(async () => {
  for (const [route, id] of created) await wp(`${route}/${id}?force=true`, { method: "DELETE" });
  await reset();
});

const row = (page, postId) => {
  const m = page.match(new RegExp(`<tr data-form-id="[^"]*" data-post-id="${postId}">[\\s\\S]*?</tr>`));
  assert.ok(m, `no row for post ${postId}`);
  return m[0];
};

test("the Forms menu opens on the list; Submissions keeps its URL", async () => {
  const dash = (await s.get("/wp-admin/index.php")).text;
  const li = dash.match(/<li[^>]*id="toplevel_page_estrategia-web-forms"[\s\S]*?<\/ul>/)[0];
  const links = [...li.matchAll(/<a href=['"]admin\.php\?page=([^'"&]+)/g)].map((x) => x[1]);
  assert.deepEqual([...new Set(links)], ["estrategia-web-forms-list", "estrategia-web-forms", "estrategia-web-forms-settings"]);
  assert.equal((await s.get("/wp-admin/admin.php?page=estrategia-web-forms")).status, 200, "Submissions");
});

test("a form inside a synced pattern: its name, identifier, notifications, and the pages that use it", async () => {
  const page = (await s.get("/wp-admin/admin.php?page=estrategia-web-forms-list")).text;
  const r = row(page, pattern);
  assert.match(r, /data-form-id="presupuesto"/);
  assert.match(r, /estrategia-web-forms-list__title">Presupuesto</);
  assert.match(r, /Formulario de presupuesto<\/strong> <span class="description">\(Patrón/, "where: the pattern, with its type");
  assert.match(r, new RegExp(`post\\.php\\?post=${pattern}&(amp;|#038;)?action=edit`), "an Edit link to the pattern");
  assert.match(r, new RegExp(`<li data-post-id="${user.id}">`), "the page that uses it");
  assert.match(r, /2 avisos/, "how many notifications");
  assert.match(r, /Aviso al equipo[\s\S]*?<code>equipo@ejemplo\.test, \{\{email\}\}<\/code>/, "label and recipients as written, markers included");
  assert.match(r, /Aviso 2[\s\S]*?<code>\{\{email\}\}<\/code>/, "a notification without a label is numbered");
});

test("drafts are listed (with their status); a page that only MENTIONS the block is not; a page using the pattern is not a form row itself", async () => {
  const page = (await s.get("/wp-admin/admin.php?page=estrategia-web-forms-list")).text;
  assert.match(row(page, draft), /Contacto \(borrador\)<\/strong> <span class="description">\(Página, Borrador\)/);
  assert.ok(!page.includes(`data-post-id="${mention}">`), "the mention is not a form");
  assert.ok(!new RegExp(`<tr data-form-id="[^"]*" data-post-id="${user.id}">`).test(page), "the user page is under the pattern, not a row");
});
