#!/usr/bin/env node
/*
 * Extracts the Mermaid blocks listed in university/figures/manifest.yaml from ../docs into
 * university/figures/<id>.mmd, and renders them to PNG when mermaid-cli (mmdc) is available.
 * Usage: node tools/export-figures.js
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const repo = path.resolve(root, '..');
const figDir = path.join(root, 'university', 'figures');
const manifest = fs.readFileSync(path.join(figDir, 'manifest.yaml'), 'utf8');

const localMmdc = path.join(root, 'node_modules', '.bin', 'mmdc');
let mmdc = fs.existsSync(localMmdc) ? localMmdc : null;
if (!mmdc) { try { execFileSync('mmdc', ['--version'], { stdio: 'ignore' }); mmdc = 'mmdc'; } catch { /* not installed */ } }
// Uses the system Chrome (PUPPETEER_EXECUTABLE_PATH or puppeteer.json) instead of downloading one.
const puppeteerCfg = path.join(root, 'puppeteer.json');

for (const line of manifest.split('\n')) {
  const m = line.match(/id: ([\w-]+), caption: "[^"]*", source: ([^,}]+?)(?:, block: (\w+))? \}/);
  if (!m) continue;
  const [, id, source, block] = m;
  if (!source.startsWith('docs/') || block === undefined || block === 'null') continue;
  const text = fs.readFileSync(path.join(repo, source), 'utf8');
  const blocks = [...text.matchAll(/```mermaid\n([\s\S]*?)```/g)].map((x) => x[1]);
  const idx = Number(block);
  if (idx >= blocks.length) { console.warn(`${id}: block ${idx} not found in ${source}`); continue; }
  const mmd = path.join(figDir, `${id}.mmd`);
  fs.writeFileSync(mmd, blocks[idx]);
  if (mmdc) {
    const args = ['-i', mmd, '-o', path.join(figDir, `${id}.png`), '-b', 'white', '-s', '2', '-w', '1400'];
    if (fs.existsSync(puppeteerCfg)) args.push('-p', puppeteerCfg);
    execFileSync(mmdc, args, { stdio: ['ignore', 'ignore', 'inherit'] });
    console.log(`rendered ${id}.png`);
  } else {
    console.log(`wrote ${id}.mmd (install mermaid-cli to render: pnpm dlx @mermaid-js/mermaid-cli -i ${id}.mmd -o ${id}.png)`);
  }
}
