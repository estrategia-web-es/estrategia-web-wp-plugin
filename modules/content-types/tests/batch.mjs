// NIVEL: BANCO DE TRABAJO — not a test (the runner only runs *.test.mjs). Several REST calls in ONE request to the test WordPress.
//
//   const [created, list] = await batch(
//     { method: "POST", path: "/estrategia-web/v1/content-types", body: { definition } },
//     { path: "/estrategia-web/v1/content-types" },
//     { method: "POST", path: "/estrategia-web/v1/content-types", body: { definition }, as: "author" },
//   );   // each answer is { status, json }, in order
//
// Why (28-10-2026, round 2): a request costs ~1,5 s whatever it does
// (WordPress booting in PHP-wasm), and the files of this module spent most of
// their time in requests whose work is nothing. The server side, with what it
// skips and THE TRAP (a definition saved by one call is registered from the
// NEXT request, never by a later call of the same batch), is the /batch route
// of tests/wp/estrategia-web-content-types-test.php: read it before batching.
//
// How to maintain it: a call that needs what an earlier call of the batch
// registered (a new type, its meta, its rewrite rules, a module switched on)
// goes in the next batch. Pages (HTML) and uploads stay plain fetches.
import { BASE, credentials } from "../../../tests/lib.mjs";

export async function batch(...requests) {
  // The administrator is WordPress's own user; another role needs the test users (one more request, once per process).
  if (requests.some((q) => q.as && q.as !== "administrator")) await credentials();
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1/batch", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ requests }) });
  if (!res.ok) throw new Error(`batch -> ${res.status} ${await res.text()}`);
  return (await res.json()).map((a) => ({ ...a, text: JSON.stringify(a.json) }));
}
