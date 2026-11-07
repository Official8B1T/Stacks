// ─────────────────────────────────────────────────────
//   project · Stacks
//   module  · web/a11y/a11y.mjs — WCAG 2.2 AA check of every page with axe-core
//   author  · Jiří "8B1T" Lhotský
// ─────────────────────────────────────────────────────
// Usage: node a11y.mjs <base-url> <urls-file> [report|gate]
// Every path from <urls-file> is opened at desktop and phone width and judged
// by axe-core against the WCAG 2.0–2.2 A and AA rules. gate = exit 1 on any
// violation, report = list them and exit 0.
// NOTE · uses the Chrome already installed on the runner (channel), so nothing
//        is downloaded; A11Y_CHANNEL=msedge runs it on a Windows machine.
// NOTE · automated rules cover only part of WCAG; zero here means
//        "no machine-detectable failure", not "accessible".

import { readFileSync, appendFileSync } from 'node:fs';
import { chromium } from 'playwright-core';
import { AxeBuilder } from '@axe-core/playwright';

const [base, urlsFile, mode = 'gate'] = process.argv.slice(2);
if (!base || !urlsFile || !['report', 'gate'].includes(mode)) {
  console.error('usage: node a11y.mjs <base-url> <urls-file> [report|gate]');
  process.exit(2);
}

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const WIDTHS = [1280, 390]; // the layout changes at 720 and 900px; phone and desktop cover both sides
const paths = readFileSync(urlsFile, 'utf8').split(/\r?\n/).map((s) => s.trim()).filter(Boolean);
// an empty list would pass every page it never opened
if (paths.length === 0) {
  console.error('FAIL  no URLs to check');
  process.exit(1);
}

const browser = await chromium.launch({ channel: process.env.A11Y_CHANNEL || 'chrome' });
const found = []; // { path, width, id, impact, help, nodes }

try {
  for (const width of WIDTHS) {
    // axe opens helper pages in the same context, browser.newPage() would not allow it
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await context.newPage();
    for (const path of paths) {
      const res = await page.goto(new URL(path, base).href, { waitUntil: 'load' });
      if (!res || res.status() !== 200) {
        throw new Error(`${path} answered ${res ? res.status() : 'nothing'}, expected 200`);
      }
      const { violations } = await new AxeBuilder({ page }).withTags(TAGS).analyze();
      for (const v of violations) {
        found.push({ path, width, id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.map((n) => n.target.join(' ')) });
      }
      console.log(`${violations.length ? 'FAIL' : 'ok  '}  ${path} @${width}px · ${violations.length} rule(s)`);
    }
    await context.close();
  }
} finally {
  await browser.close();
}

for (const f of found) {
  console.log(`      ${f.path} @${f.width}px  ${f.id} (${f.impact}) ×${f.nodes.length}: ${f.help}`);
  for (const t of f.nodes.slice(0, 3)) console.log(`          ${t}`);
}

if (process.env.GITHUB_STEP_SUMMARY) {
  const rows = found.map((f) => `| ${f.path} | ${f.width} | \`${f.id}\` | ${f.impact} | ${f.nodes.length} | ${f.help} |`);
  appendFileSync(process.env.GITHUB_STEP_SUMMARY, found.length
    ? `### Accessibility (WCAG 2.2 AA, axe-core): ${found.length} finding(s), mode ${mode}\n\n| page | px | rule | impact | elements | what |\n|---|---|---|---|---|---|\n${rows.join('\n')}\n`
    : `### Accessibility (WCAG 2.2 AA, axe-core): no machine-detectable failure on ${paths.length} page(s)\n`);
}

console.log(`\n${found.length} finding(s) on ${paths.length} page(s) × ${WIDTHS.length} widths, mode ${mode}`);
process.exit(mode === 'gate' && found.length > 0 ? 1 : 0);
