// NIVEL: 1 — no browser or emulator. That every string attribute of every block.json of the plugin is decided for WPML: declared in wpml-config.xml (<gutenberg-blocks>) so the translation editor offers it, or in EXCLUDED below with its reason.
//
// David, 17-10-2026: «the texts of blocks that the visitor reads have to be
// translated, no matter what». Until 1.4.3 only the forms were declared, and
// the WhatsApp message, the map's address and hours, the carousel's name and
// the cookie settings link were copied from the original in every language.
//
// Reads the files as text (no WordPress, no WPML). The check with real WPML
// (the attributes offered as strings of the page's translation package, and
// /en/ showing them translated after the job is completed) lives in
// Lavandería (tests/wpml-blocks.test.mjs), because Playground has no WPML.
//
// How to maintain: a new string attribute in a block.json goes into the XML
// or into EXCLUDED, in the same commit. EXCLUDED holds only what a person
// does NOT read; number, boolean and enum attributes are skipped on their
// own (an enum is a fixed list of keys, nothing to translate). The edge case
// that teaches the rule: «title» of the form is not on the page, and it IS
// declared, because it is the default subject of the emails.
//
// Seen red (17-10-2026): with the wpml-config.xml of 1.4.3, «every string
// attribute is decided» fails naming the eleven that were missing
// (whatsapp message and label, the six of the map, carousel label, consent
// label, form title).
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");

/** «block/attribute» => why it is NOT translated. */
const EXCLUDED = {
  "estrategia-web-forms/form.formId": "a key: it groups the submissions log; translating it would split it",
  "estrategia-web-forms/text.name": "a key: the field's name in the log and the {{markers}}",
  "estrategia-web-forms/text.autocomplete": "a browser token (name, organization…), the same in every language",
  "estrategia-web-forms/email.name": "a key: the field's name in the log and the {{markers}}",
  "estrategia-web-forms/email.autocomplete": "a browser token, the same in every language",
  "estrategia-web-forms/tel.name": "a key: the field's name in the log and the {{markers}}",
  "estrategia-web-forms/tel.autocomplete": "a browser token, the same in every language",
  "estrategia-web-forms/textarea.name": "a key: the field's name in the log and the {{markers}}",
  "estrategia-web-forms/textarea.autocomplete": "a browser token, the same in every language",
  "estrategia-web-forms/select.name": "a key: the field's name in the log and the {{markers}}",
  "estrategia-web-forms/select.autocomplete": "a browser token, the same in every language",
  "estrategia-web-forms/checkbox.name": "a key: the field's name in the log and the {{markers}}",
  "estrategia-web-forms/checkbox.placeholder": "not rendered: a checkbox has no placeholder (Blocks::render_field skips it)",
  "estrategia-web-forms/notification.label": "only the admin reads it, in the submissions log",
  "estrategia-web-forms/notification.to": "the recipient: the translator must not be able to redirect the emails",
  "estrategia-web-forms/notification.replyTo": "an address or a {{marker}}, the same in every language",
  "estrategia-web/whatsapp.phone": "a phone number, the same in every language",
  "estrategia-web/map.phone": "a phone number, the same in every language",
  "estrategia-web/map.imageUrl": "the same picture in every language (its alt text, imageAlt, is translated)",
  "estrategia-web/map.staticMapUrl": "the same picture in every language, served by the site",
  "estrategia-web/carousel.imageSizes": "a technical value (the sizes attribute of the images)",
  "estrategia-web/carousel.mobileRatio": "a technical value (an aspect ratio)",
  "estrategia-web/carousel.mobileImageSizes": "a technical value (the sizes attribute on mobile)",
};

// The XML without its comments: they quote entries as examples.
const xml = fs.readFileSync(path.join(ROOT, "wpml-config.xml"), "utf8").replace(/<!--[\s\S]*?-->/g, "");
const section = xml.match(/<gutenberg-blocks>([\s\S]*?)<\/gutenberg-blocks>/)?.[1] ?? "";
const attrsOf = (tag) => Object.fromEntries([...tag.matchAll(/([a-z-]+)="([^"]*)"/g)].map((m) => [m[1], m[2]]));

/** block name => [{ name, attrs }] of its <key>s. */
const declared = {};
const blockAttrs = {};
for (const m of section.matchAll(/<gutenberg-block ([^>]*)>([\s\S]*?)<\/gutenberg-block>/g)) {
  const attrs = attrsOf(m[1]);
  blockAttrs[attrs.type] = attrs;
  declared[attrs.type] = [...m[2].matchAll(/<key ([^>]*?)\/?>/g)].map((k) => attrsOf(k[1]));
}

function blockJsons(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === "node_modules" || entry.name.startsWith(".")) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) blockJsons(full, out);
    else if (entry.name === "block.json") out.push(full);
  }
  return out;
}
const blocks = blockJsons(path.join(ROOT, "modules")).map((file) => JSON.parse(fs.readFileSync(file, "utf8").replace(/^﻿/, "")));
/** «block.attribute» of every string attribute that is not an enum. */
const textAttributes = blocks.flatMap((b) =>
  Object.entries(b.attributes ?? {})
    .filter(([, a]) => a.type === "string" && !Array.isArray(a.enum))
    .map(([name]) => `${b.name}.${name}`)
);
const declaredKeys = Object.entries(declared).flatMap(([block, keys]) => keys.map((k) => `${block}.${k.name}`));

test("the files are read (if this fails, the test is not looking at anything)", () => {
  assert.ok(blocks.length >= 16, `only ${blocks.length} block.json found`);
  assert.ok(textAttributes.includes("estrategia-web/whatsapp.message"), textAttributes.join(", "));
  assert.ok(declaredKeys.includes("estrategia-web-forms/form.successMessage"), declaredKeys.join(", "));
});

test("every string attribute of every block.json is decided: declared in wpml-config.xml or in EXCLUDED", () => {
  const undecided = textAttributes.filter((k) => !declaredKeys.includes(k) && !(k in EXCLUDED));
  assert.deepEqual(undecided, [], "declare them in <gutenberg-blocks> or add them to EXCLUDED with the reason");
});

test("no attribute is declared and excluded at once", () => {
  assert.deepEqual(declaredKeys.filter((k) => k in EXCLUDED), []);
});

test("every declared key and every exclusion is a string attribute that exists in its block.json", () => {
  assert.deepEqual(declaredKeys.filter((k) => !textAttributes.includes(k)), [], "declared in the XML, not in any block.json");
  assert.deepEqual(Object.keys(EXCLUDED).filter((k) => !textAttributes.includes(k)), [], "in EXCLUDED, not in any block.json");
  for (const [k, why] of Object.entries(EXCLUDED)) assert.ok(why.length > 10, `${k}: the reason is missing`);
});

test("every <gutenberg-block> is translate=\"1\", names a block of the plugin and uses only attributes WPML's XSD admits", () => {
  const names = blocks.map((b) => b.name);
  for (const [type, attrs] of Object.entries(blockAttrs)) {
    assert.ok(names.includes(type), `${type}: no block.json with that name`);
    assert.equal(attrs.translate, "1", `${type}: translate="${attrs.translate}"`);
    assert.deepEqual(Object.keys(attrs).filter((a) => !["type", "translate", "label"].includes(a)), [], `${type}: attribute WPML's XSD does not admit`);
  }
  // res/xsd/wpml-config.xsd of WPML 5.0.2 (wpml-generic-setting-key); an invalid one drops the whole file.
  const allowed = ["name", "label", "type", "sub-type", "translate_link_target", "encoding", "search-method"];
  for (const [type, keys] of Object.entries(declared)) {
    for (const key of keys) {
      assert.deepEqual(Object.keys(key).filter((a) => !allowed.includes(a)), [], `${type}.${key.name}`);
      if (key.type) assert.equal(key.type, "link", `${type}.${key.name}: type="${key.type}" (only "link" is a string type we use)`);
    }
  }
});

test("what David asked for, by name (17-10-2026): the WhatsApp message and label, the map's texts, the forms' texts", () => {
  for (const k of ["estrategia-web/whatsapp.message", "estrategia-web/whatsapp.label", "estrategia-web/map.address", "estrategia-web/map.hours", "estrategia-web/carousel.label", "estrategia-web-site/consent.label", "estrategia-web-forms/select.options", "estrategia-web-forms/submit.text", "estrategia-web-forms/notification.subject"]) {
    assert.ok(declaredKeys.includes(k), k);
  }
  for (const k of ["estrategia-web-forms/notification.to", "estrategia-web-forms/select.name", "estrategia-web/whatsapp.phone"]) {
    assert.ok(!declaredKeys.includes(k), `${k} must NOT be translated`);
  }
});
