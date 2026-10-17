// NIVEL: 1 — no browser, no emulator. The rules of the estrategia-web/content-field binding (includes/class-field-format.php), type by type and attribute by attribute, with the machine's PHP and fake lookups (tests/php/format.php says what each fake returns).
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// Seen red (16-10-2026): with select returning the stored value, «select»
// failed («€€» instead of «€€ — mitjà»); with the boolean ignoring
// true_label, «boolean» failed; with relation in a text attribute returning
// the ID, «relation» failed («7» instead of the title).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const HERE = import.meta.dirname;
const run = (cases) => {
  const r = spawnSync("php", [path.join(HERE, "php", "format.php")], { encoding: "utf8", input: JSON.stringify(cases) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
// One field, several (raw, attribute, args, locale) → the outputs, in order.
const check = (field, rows) => {
  const got = run(rows.map(([raw, attribute, args, locale]) => ({ field, raw, attribute, args, locale })));
  assert.deepEqual(got, rows.map((r) => r[4]));
};

const price = {
  name: "gment_price_range",
  type: "select",
  label: "Preu",
  options: [
    { value: "€", label: { ca: "€ — econòmic", es: "€ — económico", en: "€ — budget" } },
    { value: "€€", label: { ca: "€€ — mitjà", es: "€€ — medio", en: "€€ — mid-range" } },
    "Tancat",
  ],
};

test("select: the option's label in the request's language, not the stored value", () => {
  check(price, [
    ["€€", "content", {}, "ca", "€€ — mitjà"],
    ["€€", "content", {}, "es_ES", "€€ — medio"],
    ["€", "content", {}, "en_US", "€ — budget"],
    ["€", "content", {}, "de_DE", "€ — budget"],
    ["Tancat", "content", {}, "ca", "Tancat"],
    ["€€€€", "content", {}, "ca", "€€€€"], // an option removed from the file after saving: the value itself
    ["", "content", {}, "ca", ""],
    ["€€", "url", {}, "ca", ""],
  ]);
});

test("boolean: translated Yes/No by default, the definition's texts if it has them, \"\" for nothing", () => {
  check({ name: "gment_organic", type: "boolean", label: "Ecològic" }, [
    [true, "content", {}, "ca", "Sí"],
    ["1", "content", {}, "ca", "Sí"],
    ["", "content", {}, "ca", "No"],
    [false, "content", {}, "ca", "No"],
    [true, "url", {}, "ca", ""],
  ]);
  check({ name: "gment_terrace", type: "boolean", label: "Terrassa", true_label: { ca: "Amb terrassa", es: "Con terraza" }, false_label: "" }, [
    ["1", "content", {}, "ca", "Amb terrassa"],
    ["1", "content", {}, "es_ES", "Con terraza"],
    ["", "content", {}, "ca", ""],
  ]);
});

test("image: URL (with size) in url, ID in id, alt in text attributes and title in title; nothing when it is not an image", () => {
  const photo = { name: "gment_photo", type: "image", label: "Foto" };
  check(photo, [
    [5, "url", {}, "ca", "https://x.test/photo-full.jpg"],
    ["5", "url", { size: "large" }, "ca", "https://x.test/photo-large.jpg"],
    [5, "id", {}, "ca", "5"],
    [5, "alt", {}, "ca", "Façana &quot;nova&quot;"],
    [5, "content", {}, "ca", "Façana &quot;nova&quot;"],
    [5, "title", {}, "ca", "Foto"],
    [6, "url", {}, "ca", ""],
    [0, "content", {}, "ca", ""],
  ]);
});

test("relation: the (translated) post's title, its permalink in url, its ID in id; a link only if asked; never the raw ID", () => {
  const winery = { name: "gment_winery", type: "relation", label: "Restaurant", post_type: "gment_restaurant" };
  check(winery, [
    [7, "content", {}, "ca", "Ca n&#039;Andreu &amp; Co"],
    [7, "url", {}, "ca", "https://x.test/ca/restaurants/ca-nandreu/"],
    [7, "id", {}, "ca", "70"],
    [7, "content", { link: true }, "ca", '<a href="https://x.test/ca/restaurants/ca-nandreu/">Ca n&#039;Andreu &amp; Co</a>'],
    [7, "url", { link: true }, "ca", "https://x.test/ca/restaurants/ca-nandreu/"],
    [8, "content", {}, "ca", ""], // not published
    [0, "content", {}, "ca", ""],
  ]);
  check({ ...winery, post_type: ["post"] }, [[7, "content", {}, "ca", ""]]);
});

test("date: the site's format (or the binding's), and nothing for an empty or impossible date", () => {
  const opened = { name: "gment_opened", type: "date", label: "Obert" };
  check(opened, [
    ["2026-10-16", "content", {}, "ca", "D:2026-10-16|"],
    ["2026-10-16", "content", { format: "j F Y" }, "ca", "D:2026-10-16|j F Y"],
    ["2026-02-30", "content", {}, "ca", ""],
    ["", "content", {}, "ca", ""],
    ["2026-10-16", "url", {}, "ca", ""],
  ]);
});

test("number: localized, with the decimals that were stored (0 for integer, or the binding's)", () => {
  check({ name: "gment_alcohol", type: "number", label: "Alcohol" }, [
    ["13.5", "content", {}, "ca", "N:13.5|1"],
    ["13.50", "content", {}, "ca", "N:13.5|1"],
    ["14", "content", {}, "ca", "N:14|0"],
    [0, "content", {}, "ca", "N:0|0"], // «0 €» is an answer: see the header of Field_Format
    ["13.5", "content", { decimals: 2 }, "ca", "N:13.5|2"],
    ["abc", "content", {}, "ca", ""],
    ["13.5", "url", {}, "ca", ""],
  ]);
  check({ name: "gment_vintage", type: "number", label: "Anyada", integer: true }, [["2019", "content", {}, "ca", "N:2019|0"]]);
});

test("url, email, tel: as they are in text (escaped), as a link when asked, and as a URL in url/href", () => {
  check({ name: "gment_website", type: "url", label: "Web" }, [
    ["https://canandreu.test/?a=1&b=2", "content", {}, "ca", "https://canandreu.test/?a=1&amp;b=2"],
    ["https://canandreu.test/", "url", {}, "ca", "https://canandreu.test/"],
    ["https://canandreu.test/", "content", { link: true }, "ca", '<a href="https://canandreu.test/">https://canandreu.test/</a>'],
    ["javascript:alert(1)", "url", {}, "ca", ""],
    ["javascript:alert(1)", "content", {}, "ca", ""],
  ]);
  check({ name: "gment_contact", type: "email", label: "Correu" }, [
    ["hola@canandreu.test", "content", {}, "ca", "hola@canandreu.test"],
    ["hola@canandreu.test", "url", {}, "ca", "mailto:hola@canandreu.test"],
    ["hola@canandreu.test", "content", { link: true }, "ca", '<a href="mailto:hola@canandreu.test">hola@canandreu.test</a>'],
  ]);
  check({ name: "gment_phone", type: "tel", label: "Telèfon" }, [
    ["+34 971 (00) 00-00", "content", {}, "ca", "+34 971 (00) 00-00"],
    ["+34 971 (00) 00-00", "href", {}, "ca", "tel:+34971000000"],
    ["971 00 00 00", "content", { link: true }, "ca", '<a href="tel:971000000">971 00 00 00</a>'],
    ["", "url", {}, "ca", ""],
  ]);
});

test("into an HTML attribute (core/image's alt): plain text, because core escapes it; no <br>, no link", () => {
  const plain = (field, raw, attribute, args = {}) => run([{ field, raw, attribute, args, locale: "ca", html: false }])[0];
  assert.equal(plain({ name: "gment_photo", type: "image", label: "Foto" }, 5, "alt"), 'Façana "nova"');
  assert.equal(plain({ name: "gment_winery", type: "relation", label: "R", post_type: "gment_restaurant" }, 7, "title", { link: true }), "Ca n'Andreu & Co");
  assert.equal(plain({ name: "gment_address", type: "textarea", label: "A" }, "a & b\nc", "alt"), "a & b\nc");
  assert.equal(plain(price, "€€", "title"), "€€ — mitjà");
});

test("text and textarea: escaped, line breaks as <br>; no HTML gets through", () => {
  check({ name: "gment_address", type: "textarea", label: "Adreça" }, [
    ["Carrer Major, 1\nPalma", "content", {}, "ca", "Carrer Major, 1<br>\nPalma"],
    ["<b>x</b>", "content", {}, "ca", "&lt;b&gt;x&lt;/b&gt;"],
  ]);
  check({ name: "gment_tasting", type: "text", label: "Nota" }, [
    ['"Fruity" & <fresh>', "content", {}, "ca", "&quot;Fruity&quot; &amp; &lt;fresh&gt;"],
    ["x", "id", {}, "ca", ""],
  ]);
});

// 17-10-2026, the field types of the old modules/site types (templates/). Seen red: without the rating case in Field_Format::format() it gave "" for 4; and a site path
// (/wp-content/…) came out "" while it went through esc_url_raw (the shim, like WordPress for a scheme it is not given, refuses it).
test("rating, embed, local_image: the number (nothing for 0), the embed URL, and an image of this site resolved from the theme", () => {
  check({ name: "gment_stars", type: "rating", label: "Valoració" }, [
    [4, "content", {}, "ca", "4"],
    [0, "content", {}, "ca", ""],
    [9, "content", {}, "ca", "5"],
    [4, "url", {}, "ca", ""],
  ]);
  check({ name: "gment_map", type: "embed", label: "Mapa" }, [
    ["https://www.google.com/maps/embed?pb=1", "url", {}, "ca", "https://www.google.com/maps/embed?pb=1"],
    ["https://www.google.com/maps/embed?pb=1", "content", {}, "ca", "https://www.google.com/maps/embed?pb=1"],
    ["", "url", {}, "ca", ""],
  ]);
  check({ name: "gment_static", type: "local_image", label: "Mapa estàtic" }, [
    ["assets/maps/seu.webp", "url", {}, "ca", "https://x.test/theme/assets/maps/seu.webp"],
    ["/wp-content/uploads/mapa.webp", "url", {}, "ca", "/wp-content/uploads/mapa.webp"],
    ["https://x.test/a.webp", "url", {}, "ca", "https://x.test/a.webp"],
    ["assets/maps/seu.webp", "content", {}, "ca", "assets/maps/seu.webp"],
    ["assets/maps/seu.webp", "id", {}, "ca", ""],
  ]);
});
