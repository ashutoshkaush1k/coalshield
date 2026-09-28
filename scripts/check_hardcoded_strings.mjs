// Finds UI text written directly in the frontend instead of going through t() (Phase 6).
//
//   node scripts/check_hardcoded_strings.mjs            list findings; exit 1 if there are any
//   node scripts/check_hardcoded_strings.mjs --json     the same as JSON
//
// Parses every .js/.jsx file under frontend/src (not i18n/) with @babel/parser and reports:
//   - JSX text with letters (<h2>Overview</h2>);
//   - string literals in text-bearing JSX attributes (label="...", title="...", placeholder="...");
//   - string / template literals rendered as JSX children ({"Loading"}, {cond ? "Yes" : "No"});
//   - label / title / caption / subtitle / hint properties of object literals (tab lists).
// Tokens that are not prose - class names, ids, codes, units, URLs - are ignored, and so is anything
// listed in ALLOW (brand and product names, symbols).
import { readdirSync, readFileSync, statSync } from "node:fs";
import { createRequire } from "node:module";
import { join, relative, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..");
const SRC = join(ROOT, "frontend", "src");
const require = createRequire(join(ROOT, "frontend", "package.json"));
const { parse } = require("@babel/parser");

const TEXT_ATTRS = new Set(["label", "title", "placeholder", "alt", "aria-label", "subtitle", "caption", "hint", "empty",
  "description", "tooltip", "legend", "emptyText", "confirmLabel", "cancelLabel", "heading", "context"]);
const TEXT_PROPS = new Set(["label", "title", "caption", "subtitle", "hint", "heading", "description", "placeholder", "empty",
  "body", "message", "context"]);
// Not translated: product and brand names, and single symbols.
const ALLOW = new Set(["Smart Mine Governance", "SIH26024", "Leaflet", "OpenStreetMap", "CoalShield", "DGMS", "PPE", "GEM", "ID"]);

const prose = (s) => {
  const v = s.replace(/\s+/g, " ").trim();
  if (!v || ALLOW.has(v)) return false;
  if (!/[A-Za-z]{2,}/.test(v)) return false;                        // symbols, numbers, "·", "-"
  if (/^(https?:|\/|#|\.|[a-z0-9_-]+$|[a-z]+(-[a-z0-9]+)+$)/.test(v)) return false; // urls, paths, ids, class names, codes
  if (/^[A-Z0-9_]+$/.test(v)) return false;                          // CONSTANT_CODES
  if (/^[a-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)+$/.test(v)) return false; // translation keys (a.b.c)
  return true;
};

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) {
      if (name !== "i18n" && name !== "node_modules") walk(p, out);
    } else if (/\.(jsx?|mjs)$/.test(name)) out.push(p);
  }
  return out;
}

function visit(node, fn, parent = null) {
  if (!node || typeof node.type !== "string") return;
  fn(node, parent);
  for (const key of Object.keys(node)) {
    if (key === "loc" || key === "start" || key === "end") continue;
    const v = node[key];
    if (Array.isArray(v)) v.forEach((c) => c && typeof c.type === "string" && visit(c, fn, node));
    else if (v && typeof v.type === "string") visit(v, fn, node);
  }
}

const findings = [];
for (const file of walk(SRC)) {
  const code = readFileSync(file, "utf8");
  let ast;
  try {
    ast = parse(code, { sourceType: "module", plugins: ["jsx"] });
  } catch (e) {
    findings.push({ file: relative(ROOT, file), line: e.loc?.line ?? 0, kind: "parse-error", text: e.message });
    continue;
  }
  const add = (node, kind, text) => findings.push({ file: relative(ROOT, file).replace(/\\/g, "/"), line: node.loc.start.line, kind, text: text.replace(/\s+/g, " ").trim() });
  const literalText = (n) => n.type === "StringLiteral" ? n.value : n.type === "TemplateLiteral" ? n.quasis.map((q) => q.value.cooked).join(" ") : null;
  const inJsxChild = (n) => {
    // a literal, or the branches of a conditional / logical expression rendered as a child
    if (n.type === "ConditionalExpression") return [n.consequent, n.alternate].flatMap(inJsxChild);
    if (n.type === "LogicalExpression") return inJsxChild(n.right);
    const s = literalText(n);
    return s === null ? [] : [[n, s]];
  };
  visit(ast.program, (node, parent) => {
    if (node.type === "JSXText" && prose(node.value)) add(node, "jsx-text", node.value);
    if (node.type === "JSXAttribute" && node.value) {
      const name = node.name.name;
      if (!TEXT_ATTRS.has(name)) return;
      if (node.value.type === "StringLiteral" && prose(node.value.value)) add(node, `attr:${name}`, node.value.value);
      if (node.value.type === "JSXExpressionContainer") {
        for (const [n, s] of inJsxChild(node.value.expression)) if (prose(s)) add(n, `attr:${name}`, s);
      }
    }
    if (node.type === "JSXExpressionContainer" && parent && (parent.type === "JSXElement" || parent.type === "JSXFragment")) {
      for (const [n, s] of inJsxChild(node.expression)) if (prose(s)) add(n, "jsx-expression", s);
    }
    // Default text of a component prop: function X({ children = "Nothing to show yet." })
    if (node.type === "AssignmentPattern" && parent?.type === "ObjectProperty") {
      const s = literalText(node.right);
      if (s !== null && prose(s) && /\s/.test(s)) add(node, "default-prop", s);
    }
    if (node.type === "ObjectProperty" && !node.computed) {
      const key = node.key.name ?? node.key.value;
      if (TEXT_PROPS.has(key)) {
        const s = literalText(node.value);
        if (s !== null && prose(s)) add(node, `prop:${key}`, s);
      }
    }
  });
}

if (process.argv.includes("--json")) {
  console.log(JSON.stringify(findings, null, 2));
} else {
  const byFile = {};
  for (const f of findings) (byFile[f.file] ??= []).push(f);
  for (const [file, list] of Object.entries(byFile)) {
    console.log(file);
    for (const f of list) console.log(`  ${String(f.line).padStart(4)}  ${f.kind.padEnd(16)} ${f.text.slice(0, 90)}`);
  }
  console.log(`\n${findings.length} hard-coded UI string(s) in ${Object.keys(byFile).length} file(s).`);
}
process.exit(findings.length ? 1 : 0);
