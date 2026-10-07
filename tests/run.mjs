// NIVEL: BANCO DE TRABAJO — no es una prueba: es el corredor (Armazón, fila 7).
//
//   node tests/run.mjs             1 → 2 → 3, para en el primer nivel rojo
//   node tests/run.mjs --nivel 1   solo el 1 (lo de cada commit: ~0,2 s)
//   node tests/run.mjs webp        el 1 y, de los demás, solo webp.test.mjs (al desarrollar)
//
// Descubre las pruebas (tests/*.test.mjs) y lee el nivel de su PRIMERA
// línea; una prueba sin nivel declarado para la tanda entera. No hay lista.
//
// Si hace falta el nivel 2 o 3 y no hay WordPress en el puerto 9404, ARRANCA
// Playground (WP 7.1, PHP 8.3, el plugin montado y tests/blueprint.json) y lo
// MATA al terminar con taskkill /T /F: matar al padre no mata a los hijos en
// Windows. Si ya había uno corriendo, lo deja como estaba.
//
// Puertos de la familia: 9401 el tema padre, 9402 formularios, 9404 este.
//
// La trampa (la misma que en armazon-wp-formularios): desde Git Bash,
// `npx … --mount-dir E:/x /wordpress/…` llega con la ruta de Linux convertida
// a «C:/Program Files/Git/wordpress/…» y el montaje falla con
// «[object Object]». Lanzado desde aquí (Node, sin MSYS) no pasa. Si lo
// lanzas a mano desde Git Bash: MSYS_NO_PATHCONV=1 delante.
import { spawn, spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");
const PORT = 9404;
const BASE = `http://127.0.0.1:${PORT}`;
const only = process.argv.includes("--nivel") ? process.argv[process.argv.indexOf("--nivel") + 1] : null;

// Pruebas sueltas por nombre (0.5.2): `node tests/run.mjs external-links`
// corre el nivel 1 entero y, de los demás, solo las que casen. Es la regla de
// la fila 7 («Cuándo corre la batería»): al desarrollar, el 1 siempre y la
// prueba nueva; la tanda entera, bajo demanda o antes de publicar.
const picked = process.argv.slice(2).filter((a, i, all) => !a.startsWith("--") && all[i - 1] !== "--nivel");
const files = fs.readdirSync(path.join(ROOT, "tests")).filter((f) => f.endsWith(".test.mjs")).sort()
  .filter((f) => !picked.length || picked.some((p) => f.startsWith(p.replace(/\.test\.mjs$/, ""))) || fs.readFileSync(path.join(ROOT, "tests", f), "utf8").startsWith("// NIVEL: 1"));
const byLevel = {};
for (const f of files) {
  const first = fs.readFileSync(path.join(ROOT, "tests", f), "utf8").split("\n")[0];
  const m = first.match(/^\/\/ NIVEL: (\d)\b/);
  if (!m) {
    console.error(`✖ ${f} no declara su nivel en la primera línea («// NIVEL: n — …»). No se corre nada.`);
    process.exit(1);
  }
  (byLevel[m[1]] ??= []).push(f);
}

const levels = Object.keys(byLevel).sort().filter((l) => !only || l === only);
let server = null;

async function up() {
  try {
    return (await fetch(BASE + "/wp-json/")).ok;
  } catch {
    return false;
  }
}

async function startPlayground() {
  if (await up()) return;
  console.log(`· arrancando Playground en el puerto ${PORT}…`);
  server = spawn(
    "npx",
    ["-y", "@wp-playground/cli@latest", "server", "--wp=7.1", "--php=8.3", `--port=${PORT}`,
      "--mount-dir", ROOT, "/wordpress/wp-content/plugins/estrategia-web-site",
      "--blueprint=" + path.join(ROOT, "tests", "blueprint.json"), "--login"],
    { cwd: ROOT, shell: true, stdio: ["ignore", "pipe", "pipe"] }
  );
  await new Promise((resolve, reject) => {
    let log = "";
    const onData = (d) => {
      log += d;
      fs.appendFileSync(path.join(ROOT, "tests", ".playground.log"), d);
      if (log.includes("Ready!")) resolve();
      else if (/\bError\b/.test(log)) reject(new Error(log));
    };
    server.stdout.on("data", onData);
    server.stderr.on("data", onData);
    server.on("exit", (code) => reject(new Error("Playground salió con " + code + "\n" + log)));
  });
  // «Ready!» no basta: se espera al HECHO que necesitan las pruebas, que
  // las rutas del mu-plugin de pruebas respondan.
  for (let i = 0; i < 120; i++) {
    try {
      if ((await fetch(BASE + "/wp-json/estrategia-web-site-test/v1/ping")).ok) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error("Playground arrancó pero las rutas de prueba no responden (¿falló el blueprint?). Mira tests/.playground.log");
}

function stopPlayground() {
  if (!server) return;
  if (process.platform === "win32") spawnSync("taskkill", ["/pid", String(server.pid), "/T", "/F"], { stdio: "ignore" });
  else server.kill();
}

let failed = false;
try {
  for (const level of levels) {
    if (level !== "1") await startPlayground();
    console.log(`\n=== Nivel ${level}: ${byLevel[level].join(", ")}`);
    const r = spawnSync(process.execPath, ["--test", "--test-concurrency=1", ...byLevel[level].map((f) => path.join("tests", f))], { cwd: ROOT, stdio: "inherit" });
    if (r.status !== 0) {
      console.error(`\n✖ Nivel ${level} en rojo: no se corren los siguientes.`);
      failed = true;
      break;
    }
  }
} finally {
  stopPlayground();
}
process.exit(failed ? 1 : 0);
