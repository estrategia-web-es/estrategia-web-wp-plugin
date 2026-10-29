// NIVEL: 1 — no browser, no emulator. The FAQPage of modules/faq-schema (includes/class-faq.php) with the machine's PHP: which accordions count, nesting and synced patterns, the answer's HTML, the merge into Rank Math's keyed graph and Yoast's list, and stepping aside when the host already has a FAQPage.
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// The blocks are written by hand in parse_blocks()'s shape (blockName, attrs, innerBlocks, innerHTML, innerContent
// with null where an inner block goes). The first case is the one that catches the heading read whole (the question
// would end in « +», core's icon span).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const HERE = import.meta.dirname;
const run = (cases) => {
  const r = spawnSync("php", [path.join(HERE, "php", "faq.php")], { encoding: "utf8", input: JSON.stringify(cases) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const one = (fn, ...args) => run([{ fn, args }])[0];

const block = (blockName, attrs, inner, before = "", after = "") => ({
  blockName,
  attrs,
  innerBlocks: inner,
  innerHTML: before + after,
  innerContent: [before, ...inner.map(() => null), after],
});
const p = (html) => ({ blockName: "core/paragraph", attrs: {}, innerBlocks: [], innerHTML: html, innerContent: [html] });
const item = (q, ...answer) =>
  block("core/accordion-item", {}, [
    {
      blockName: "core/accordion-heading",
      attrs: {},
      innerBlocks: [],
      innerHTML: `<h3 class="wp-block-accordion-heading"><button type="button" class="wp-block-accordion-heading__toggle"><span class="wp-block-accordion-heading__toggle-title">${q}</span><span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span></button></h3>`,
      innerContent: [],
    },
    block("core/accordion-panel", {}, answer, '<div role="region" class="wp-block-accordion-panel">', "</div>"),
  ], '<div class="wp-block-accordion-item">', "</div>");
const accordion = (className, ...items) => block("core/accordion", className ? { className } : {}, items, '<div role="group" class="wp-block-accordion">', "</div>");

test("a «faq-list» accordion: each item is a question, without the icon", () => {
  const qs = one("questions", [accordion("is-style-faq-list", item("¿Cuánto cuesta?", p("<p>250 € al mes.</p>")), item("¿Hay permanencia?", p("<p>No.</p>")))], {});
  assert.deepEqual(qs, [["¿Cuánto cuesta?", "<p>250 € al mes.</p>"], ["¿Hay permanencia?", "<p>No.</p>"]]);
});

test("Lavandería's «faq» counts; a plain accordion does not", () => {
  const qs = one("questions", [accordion("is-style-faq", item("¿Dónde está?", p("<p>En Maioris.</p>"))), accordion("", item("Servicios", p("<p>Lista</p>"))), accordion("is-style-faqx", item("No", p("<p>No</p>")))], {});
  assert.deepEqual(qs.map((q) => q[0]), ["¿Dónde está?"]);
});

test("inside a group and inside a synced pattern (and a pattern that includes itself does not loop)", () => {
  const group = block("core/group", {}, [accordion("is-style-faq-list", item("Uno", p("<p>1</p>")))], "<section>", "</section>");
  const ref = { blockName: "core/block", attrs: { ref: 7 }, innerBlocks: [], innerHTML: "", innerContent: [] };
  const patterns = { 7: [accordion("is-style-faq-list", item("Dos", p("<p>2</p>"))), ref] };
  assert.deepEqual(one("questions", [group, ref], patterns).map((q) => q[0]), ["Uno", "Dos"]);
});

test("the answer keeps Google's HTML (links with their href) and drops the rest", () => {
  const [[, a]] = one("questions", [accordion("is-style-faq-list", item("¿Y la web?", p('<p class="x">Mira <a class="l" href="https://x.test/web/" target="_blank">la web</a> <span>y</span> <strong>ya</strong>.</p>'), p("<ul><li>uno</li></ul>")))], {});
  assert.equal(a, '<p>Mira <a href="https://x.test/web/">la web</a> y <strong>ya</strong>.</p><ul><li>uno</li></ul>');
});

test("an item without question or without answer is left out; no questions, no node", () => {
  assert.deepEqual(one("questions", [accordion("is-style-faq-list", item("", p("<p>x</p>")), item("¿Vacía?"))], {}), []);
  assert.equal(one("node", "https://x.test/p/", []), null);
});

test("the node: one FAQPage with Question and acceptedAnswer", () => {
  const n = one("node", "https://x.test/p/", [["¿A?", "<p>B</p>"]]);
  assert.deepEqual(n, { "@type": "FAQPage", "@id": "https://x.test/p/#faq", url: "https://x.test/p/", mainEntity: [{ "@type": "Question", name: "¿A?", acceptedAnswer: { "@type": "Answer", text: "<p>B</p>" } }] });
});

test("merge: keyed (Rank Math), list (Yoast), and steps aside if the host has a FAQPage", () => {
  const node = { "@type": "FAQPage", "@id": "u#faq" };
  assert.deepEqual(one("merge", { publisher: { "@type": "Organization" } }, node).graph, { publisher: { "@type": "Organization" }, "estrategia-web-faq": node });
  assert.deepEqual(one("merge", [{ "@type": "WebPage" }], node).graph, [{ "@type": "WebPage" }, node]);
  const host = { faq: { "@type": ["FAQPage"], "@id": "theirs" } };
  assert.deepEqual(one("merge", host, node).graph, host);
});
