// NIVEL: 1 — no browser, no emulator. The pure part of modules/llms (includes/class-text.php) with the machine's PHP: which request is a llms.txt and of which language, the file served with «Other languages», the draft, and what is saved.
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// The case that teaches the rule is the first: /en/llms.txt is a llms.txt only
// when «en» is a language WPML puts in a directory. Without WPML, or with a
// domain per language, it is any other URL (WordPress's 404), never the root
// file served under another path.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const HERE = import.meta.dirname;
const run = (cases) => {
  const r = spawnSync("php", [path.join(HERE, "php", "text.php")], { encoding: "utf8", input: JSON.stringify(cases) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const one = (fn, ...args) => run([{ fn, args }])[0];

test("/en/llms.txt is English only if «en» is a directory language", () => {
  const [dirs, none, other] = run([
    { fn: "language_of", args: ["/en/llms.txt", "", ["es", "en"]] },
    { fn: "language_of", args: ["/en/llms.txt", "", []] },
    { fn: "language_of", args: ["/fr/llms.txt", "", ["es", "en"]] },
  ]);
  assert.equal(dirs, "en");
  assert.equal(none, null);
  assert.equal(other, null);
});

test("the root file, and nothing that only looks like it", () => {
  const r = run(
    ["/llms.txt", "/llms.txt/", "/llms.txt.bak", "/blog/llms.txt", "/es/en/llms.txt", "/llms-full.txt", "/"].map((p) => ({ fn: "language_of", args: [p, "", ["es", "en"]] })),
  );
  assert.deepEqual(r, ["", null, null, null, null, null, null]);
});

test("a WordPress in a subfolder: /sub/llms.txt and /sub/en/llms.txt, not /llms.txt", () => {
  const r = run([
    { fn: "language_of", args: ["/sub/llms.txt", "/sub/", ["en"]] },
    { fn: "language_of", args: ["/sub/en/llms.txt", "/sub", ["en"]] },
    { fn: "language_of", args: ["/llms.txt", "/sub", ["en"]] },
    { fn: "language_of", args: ["/subllms.txt", "/sub", ["en"]] },
  ]);
  assert.deepEqual(r, ["", "en", null, null]);
});

test("the body: the text with one final newline, and «Other languages» only when there are others", () => {
  assert.equal(one("body", "# Estrategia Web\n\n> Agencia\n\n\n", "Otros idiomas", []), "# Estrategia Web\n\n> Agencia\n");
  assert.equal(
    one("body", "# Estrategia Web", "Otros idiomas", [{ name: "English", url: "https://x.test/en/llms.txt" }, { name: "Català", url: "https://x.test/ca/llms.txt" }]),
    "# Estrategia Web\n\n## Otros idiomas\n\n- [English](https://x.test/en/llms.txt)\n- [Català](https://x.test/ca/llms.txt)\n",
  );
});

test("the draft: name, summary, the facts on one line (empty ones left out) and the pages", () => {
  const d = one("draft", {
    name: "Estrategia Web",
    summary: "Agencia de diseño web\nen Palma",
    facts: ["Estrategia Web Mallorca SL", "B70793757", "", "678 604 615"],
    heading: "Páginas",
    pages: [
      { title: "Contacto", url: "https://x.test/contacto/", description: "" },
      { title: "Diseño", url: "https://x.test/dise%C3%B1o/", description: "Webs a medida  y con WordPress." },
    ],
  });
  assert.equal(
    d,
    "# Estrategia Web\n\n> Agencia de diseño web en Palma\n\nEstrategia Web Mallorca SL · B70793757 · 678 604 615\n\n## Páginas\n\n- [Contacto](https://x.test/contacto/)\n- [Diseño](https://x.test/dise%C3%B1o/): Webs a medida y con WordPress.\n",
  );
});

test("a draft with nothing but the name", () => {
  assert.equal(one("draft", { name: "Sitio", summary: "", facts: ["", " "], heading: "Pages", pages: [] }), "# Sitio\n");
});

test("clean keeps Markdown as it is: %xx, <…>, accents; and normalises the line ends", () => {
  const md = "# Título\r\n\r\n- [Diseño](https://x.test/dise%C3%B1o/) <https://x.test/>\r\n";
  assert.equal(one("clean", md), "# Título\n\n- [Diseño](https://x.test/dise%C3%B1o/) <https://x.test/>");
  assert.equal(one("clean", ["not", "a", "string"]), "");
});

test("merge: what arrives on top of what is stored; empty removes; bad codes are dropped; null keeps all", () => {
  const saved = { es: "Hola", en: "Hello" };
  assert.deepEqual(one("merge", { en: "Hello again" }, saved), { es: "Hola", en: "Hello again" });
  assert.deepEqual(one("merge", { en: "  " }, saved), { es: "Hola" });
  assert.deepEqual(one("merge", null, saved), saved);
  assert.deepEqual(one("merge", { "pt-br": "Olá", "../x": "no", EN: "no", 0: "no" }, {}), { "pt-br": "Olá" });
});
