// NIVEL: BANCO DE TRABAJO — not a test. Searches the code of ALL WordPress.org plugins
// for whether a prefix or a name is already in use, before choosing it.
//
//   node tools/buscar-colisiones.mjs '[\x27\x22]eweb_'            does anyone use the string 'eweb_…?
//   node tools/buscar-colisiones.mjs '[\x27\x22]gmt_' '[\x27\x22]gmt_recipe[\x27\x22]'
//
// Uses veloria.dev's public MCP server (formerly wpdirectory.net): no key or
// account, read-only, RE2 regex, .php files only. Prints how many plugins match
// and the most installed ones.
//
// How to maintain:
// - Who and when: whoever is about to choose a prefix or a post type, taxonomy or
//   option name for the plugin or for a site's own plugin (case: the eweb_ prefix
//   for the types, 15-10-2026: 0 plugins; 13 used ew_).
// - THE PITFALL that gave a garbage number the first time: '.ew_' matches "new_" and
//   "view_" (the dot is any character). Anchor the prefix behind a quote:
//   [\x27\x22] (single or double quote). In Git Bash, type ONE backslash
//   (\x27): pairs of backslashes get lost on the way (CLAUDE.md 3.2).
// - What it does NOT see: paid plugins, custom code and themes (repo: 'themes' if
//   needed, changing `repo` below). Zero matches means "nobody in the public
//   directory", not "nobody".
// - If veloria.dev changes its MCP, the schema of the search_code tool is shown with
//   the `schema` argument.
const U = "https://veloria.dev/mcp";
const base = { "Content-Type": "application/json", Accept: "application/json,text/event-stream" };
let sid;
async function rpc(body) {
  const r = await fetch(U, { method: "POST", headers: { ...base, ...(sid ? { "Mcp-Session-Id": sid } : {}) }, body: JSON.stringify(body) });
  sid ??= r.headers.get("mcp-session-id");
  const t = await r.text();
  const m = t.match(/^data: (.*)$/m);
  return t ? JSON.parse(m ? m[1] : t) : null;
}
const args = process.argv.slice(2);
if (!args.length || args[0] === "--ayuda") {
  console.log("Usage: node tools/buscar-colisiones.mjs '<regex>' [...]   (see the header)");
  process.exit(args.length ? 0 : 1);
}
await rpc({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2025-03-26", capabilities: {}, clientInfo: { name: "buscar-colisiones", version: "1" } } });
await rpc({ jsonrpc: "2.0", method: "notifications/initialized" });
if (args[0] === "schema") {
  const l = await rpc({ jsonrpc: "2.0", id: 2, method: "tools/list" });
  for (const t of l.result.tools) if (t.name === "search_code") console.log(JSON.stringify(t, null, 1));
  process.exit(0);
}
let id = 3;
for (const q of args) {
  const r = await rpc({ jsonrpc: "2.0", id: id++, method: "tools/call", params: { name: "search_code", arguments: { repo: "plugins", query: q, file_match: "\\.php$", limit: 10 } } });
  const txt = (r?.result?.content || []).map((c) => c.text).join("\n");
  console.log(`\n### ${q}\n` + (txt || JSON.stringify(r)).split("\n").filter((l) => !/^(Use search_id|search_id)/.test(l)).join("\n").slice(0, 2500));
}
