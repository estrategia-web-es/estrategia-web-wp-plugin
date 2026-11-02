// NIVEL: 1 — no browser or emulator. The pure part of the newsletter module (includes/class-rules.php) with the machine's PHP: the 16-word lead, a block, the whole email, the photo without WebP, the datacenter and the body of the new Mailchimp campaign; and Gastronòmicament's REAL butlletí of 04-09-2026 rebuilt from its templates.
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
//
// How to maintain: a case here is a key in php/pure.php. The real case reads
// tests/fixtures/: if Gastronòmicament's design changes, regenerate them
// from a real sent campaign (the block template is its first block with the
// values replaced by {{…}}), never write them by hand.
// Seen red (02-11-2026): campaign_body() copying template_id (the 04-09-2026 trap) and the lead
// without the «…» each fail their test.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("Gastronòmicament 04-09-2026: its templates and the three posts give the butlletí that was sent, byte for byte", () => {
  // On a difference, only where it starts: the whole 6 KB twice says nothing.
  const same = (a, b, what) => {
    let i = 0;
    while (i < a.length && a[i] === b[i]) i++;
    assert.ok(a === b, `${what} differs at ${i}: got ${JSON.stringify(a.slice(i - 30, i + 30))}, expected ${JSON.stringify(b.slice(i - 30, i + 30))}`);
  };
  same(out.real.blocks, out.real.expected, "blocks");
  same(out.real.email, out.real.wrapper.replace("{{blocks}}", out.real.expected), "email");
  assert.ok(out.real.email.includes("*|UNSUB|*"), "the footer of the template is kept");
});

test("lead: 16 words and «…», shorter or exactly 16 untouched, the comma before «…» goes, HTML and entities out", () => {
  assert.deepEqual(out.excerpt, {
    short: "Una dues tres",
    exact16: "u d t q c s s v n d o d t c q s",
    seventeen: "u d t q c s s v n d o d t c q s…",
    comma: "u d t q c s s v n d o d t c q setze…",
    html: "Un bon vi & tapes",
    empty: "",
  });
});

test("block: values escaped, a value is never read as a placeholder, a missing one is empty", () => {
  assert.equal(out.block.escaped, '<a href="https://x.test/?a=1&amp;b=2" title="Vi &quot;negre&quot; &amp; &lt;b&gt;">Vi &quot;negre&quot; &amp; &lt;b&gt;</a>');
  assert.equal(out.block.no_recursion, "{{link}}|L");
  assert.equal(out.block.missing, "[]");
});

test("email: a template without {{blocks}} is refused (null), not sent without news", () => {
  assert.equal(out.email.no_marker, null);
});

test("photo: never WebP if a JPEG or PNG of it is on disk; WebP only is said", () => {
  assert.deepEqual(out.image.jpeg, { url: "https://x.test/up/a-1024x576.jpg", webp_only: false });
  assert.deepEqual(out.image.webp_sibling, { url: "https://x.test/up/a-1024x576.jpg", webp_only: false }, "the size's own .jpg");
  assert.deepEqual(out.image.webp_original, { url: "https://x.test/up/b.jpg", webp_only: false }, "uploaded as WebP: the original");
  assert.deepEqual(out.image.webp_png, { url: "https://x.test/up/c.png", webp_only: false });
  assert.deepEqual(out.image.webp_only, { url: "https://x.test/up/z-1024x576.webp", webp_only: true }, "an original that is not on disk does not count");
  assert.deepEqual(out.image.none, { url: "", webp_only: false });
});

test("datacenter: the key's suffix, lowercase; none without it", () => {
  assert.deepEqual(out.dc, { us21: "us21", spaces: "us6", none: null, bad: null });
});

test("new campaign: regular, the model's audience, segment and sender, the new subject, and NEVER its template", () => {
  const b = out.body;
  assert.equal(b.type, "regular");
  assert.equal(b.recipients.list_id, "L1");
  assert.equal(b.recipients.segment_opts.conditions[0].field, "interests-1");
  assert.equal(b.settings.from_name, "Gastronòmicament");
  assert.equal(b.settings.reply_to, "info@gastronomicament.cat");
  assert.equal(b.settings.auto_tweet, true, "the automatic tweet, as every campaign");
  assert.equal(b.settings.subject_line, "Títol ".repeat(30));
  assert.equal([...b.settings.title].length, 99, "the campaign name is cut at 99 characters, not bytes");
  assert.ok(!("template_id" in b.settings), "template_id: the 04-09-2026 trap");
  assert.ok(!("drag_and_drop" in b.settings));
  assert.ok(!("inline_css" in b.settings), "a null of the model is not sent");
  assert.deepEqual(out.body_bare, { type: "regular", recipients: { list_id: "L2" }, settings: { subject_line: "S", title: "S" } });
});
