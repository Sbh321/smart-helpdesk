#!/usr/bin/env node
/*
 * Renders SVG files to PNG at twice their size with the headless Chrome that mermaid-cli already uses
 * (puppeteer.json), for diagrams drawn directly as SVG (tools/usecase-diagrams.py).
 * Usage: node tools/render-svg.js <file.svg> [...]
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

(async () => {
  const config = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'puppeteer.json'), 'utf8'));
  const browser = await puppeteer.launch(config);
  const page = await browser.newPage();
  for (const file of process.argv.slice(2)) {
    const svg = fs.readFileSync(file, 'utf8');
    const width = Number(svg.match(/width="(\d+)"/)[1]);
    const height = Number(svg.match(/height="(\d+)"/)[1]);
    await page.setViewport({ width, height, deviceScaleFactor: 2 });
    await page.setContent(`<html><body style="margin:0">${svg}</body></html>`);
    const out = file.replace(/\.svg$/, '.png');
    await page.screenshot({ path: out, clip: { x: 0, y: 0, width, height } });
    console.log(`rendered ${path.basename(out)} (${width}x${height})`);
  }
  await browser.close();
})();
