// NIVEL: BANCO DE TRABAJO — no es una prueba. Busca en el código de TODOS los plugins de
// WordPress.org si un prefijo o un nombre ya se usa, antes de elegirlo.
//
//   node tools/buscar-colisiones.mjs '[\x27\x22]eweb_'            ¿alguien usa la cadena 'eweb_…?
//   node tools/buscar-colisiones.mjs '[\x27\x22]gmt_' '[\x27\x22]gmt_recipe[\x27\x22]'
//
// Usa el servidor MCP público de veloria.dev (antes wpdirectory.net): sin clave ni
// cuenta, solo lectura, regex RE2, solo ficheros .php. Imprime cuántos plugins casan
// y los más instalados.
//
// Cómo se mantiene:
// - Quién y cuándo: quien vaya a elegir un prefijo o un nombre de tipo, taxonomía u
//   opción para el plugin o para el plugin propio de una web (caso: el prefijo
//   eweb_ de los tipos, 15-10-2026: 0 plugins; ew_ lo usaban 13).
// - LA TRAMPA que dio un número basura la primera vez: '.ew_' casa con «new_» y
//   «view_» (el punto es cualquier carácter). Ancla el prefijo detrás de una
//   comilla: [\x27\x22] (comilla simple o doble). En Git Bash, escribe UNA barra
//   (\x27): los pares de barras se pierden por el camino (CLAUDE.md 3.2).
// - Lo que NO ve: plugins de pago, código a medida y temas (repo: 'themes' si hace
//   falta, cambiando `repo` abajo). Cero coincidencias es «nadie en el directorio
//   público», no «nadie».
// - Si veloria.dev cambia su MCP, el esquema de la herramienta search_code se ve con
//   el argumento `schema`.
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
  console.log("Uso: node tools/buscar-colisiones.mjs '<regex>' [...]   (ver la cabecera)");
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
