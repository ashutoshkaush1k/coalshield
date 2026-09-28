// Locale completeness check (Phase 6). Part of the test run (api\run_tests.bat) and `npm run i18n:check`.
//
//   node scripts/check_locales.mjs
//
// Fails (exit 1) when, in any locale other than en:
//   - a key of en.json is missing, or a key exists that en.json does not have;
//   - a value is not a string where en has a string (or the reverse);
//   - the {{placeholders}} differ from en's (a dropped {{count}} shows a sentence without its number);
//   - "_meta": {"reviewed": <boolean>} is missing.
// It also reports, without failing, how many values are still identical to English - some are
// meant to be (codes, units, product names), a large number means an untranslated file.
import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..");
const DIR = join(ROOT, "frontend", "src", "i18n", "locales");
const LOCALES = ["hi", "bn", "or", "te", "mr"];

const load = (code) => JSON.parse(readFileSync(join(DIR, `${code}.json`), "utf8"));
function flatten(obj, prefix = "", out = new Map()) {
  for (const [k, v] of Object.entries(obj)) {
    if (!prefix && k === "_meta") continue;
    const key = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === "object" && !Array.isArray(v)) flatten(v, key, out);
    else out.set(key, v);
  }
  return out;
}
const placeholders = (s) => [...String(s).matchAll(/\{\{\s*([\w.]+)\s*(?:,[^}]*)?\}\}/g)].map((m) => m[1]).sort().join(",");

const en = flatten(load("en"));
let failed = false;
console.log(`en.json: ${en.size} keys`);
for (const code of LOCALES) {
  let data;
  try {
    data = load(code);
  } catch (e) {
    console.log(`${code}.json: cannot read (${e.message})`);
    failed = true;
    continue;
  }
  const problems = [];
  if (typeof data._meta?.reviewed !== "boolean") problems.push(`"_meta": {"reviewed": ...} missing`);
  const loc = flatten(data);
  const missing = [...en.keys()].filter((k) => !loc.has(k));
  const extra = [...loc.keys()].filter((k) => !en.has(k));
  const types = [...en.keys()].filter((k) => loc.has(k) && typeof loc.get(k) !== typeof en.get(k));
  const vars = [...en.keys()].filter((k) => loc.has(k) && typeof en.get(k) === "string" && placeholders(loc.get(k)) !== placeholders(en.get(k)));
  const empty = [...en.keys()].filter((k) => loc.has(k) && typeof loc.get(k) === "string" && !loc.get(k).trim() && en.get(k).trim());
  if (missing.length) problems.push(`${missing.length} missing: ${missing.slice(0, 8).join(", ")}${missing.length > 8 ? ", ..." : ""}`);
  if (extra.length) problems.push(`${extra.length} not in en: ${extra.slice(0, 8).join(", ")}${extra.length > 8 ? ", ..." : ""}`);
  if (types.length) problems.push(`${types.length} of another type: ${types.slice(0, 5).join(", ")}`);
  if (vars.length) problems.push(`${vars.length} with other placeholders: ${vars.slice(0, 5).map((k) => `${k} (${placeholders(en.get(k))} -> ${placeholders(loc.get(k))})`).join("; ")}`);
  if (empty.length) problems.push(`${empty.length} empty: ${empty.slice(0, 5).join(", ")}`);
  const same = [...en.keys()].filter((k) => loc.get(k) === en.get(k) && /[a-z]{3,}/.test(String(en.get(k)))).length;
  const status = problems.length ? "FAIL" : "ok";
  console.log(`${code}.json: ${status}, ${loc.size} keys, reviewed: ${data._meta?.reviewed}, ${same} value(s) still identical to English`);
  for (const p of problems) console.log(`  - ${p}`);
  if (problems.length) failed = true;
}
process.exit(failed ? 1 : 0);
