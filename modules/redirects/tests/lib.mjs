// NIVEL: BANCO DE TRABAJO — no es una prueba. Ayudantes de las pruebas de nivel 2 y 3 del módulo redirects.
//
// Habla con el Playground que arranca tests/run.mjs (AS_BASE, 9404 por
// defecto) y con las rutas de modules/redirects/tests/wp/.
import fs from "node:fs";
import path from "node:path";
import { reset as coreReset, testApi as coreApi } from "../../../tests/lib.mjs";

export { BASE, playwright, seedPage, html } from "../../../tests/lib.mjs";
export { coreApi };

export const FIXTURE = path.join(import.meta.dirname, "fixtures", "lavanderia-2026-10-07.csv");
export const CSV = fs.readFileSync(FIXTURE, "utf8");

export async function api(p, { method = "GET", body } = {}) {
  const { BASE } = await import("../../../tests/lib.mjs");
  const res = await fetch(BASE + "/wp-json/estrategia-web-redirects-test/v1" + p, {
    method,
    headers: body ? { "Content-Type": "application/json" } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw new Error(`${method} ${p} -> ${res.status} ${await res.text()}`);
  return res.json();
}

/** El núcleo como recién instalado, el módulo encendido y sus tablas vacías. */
export async function reset({ on = true } = {}) {
  await coreReset();
  await api("/setup", { method: "POST", body: { on } });
  await api("/reset", { method: "POST" });
}

/** Una petición pública sin seguir redirecciones: { status, location, text }. */
export async function get(p) {
  const { BASE } = await import("../../../tests/lib.mjs");
  const res = await fetch(BASE + p, { redirect: "manual" });
  return { status: res.status, location: res.headers.get("location"), by: res.headers.get("x-redirect-by"), text: await res.text() };
}
