#!/usr/bin/env node
/*
 * Builds a report DOCX from Markdown sources, applying the CACS452 format standards:
 * A4; margins top/bottom/right 1", left 1.25"; Times New Roman 12, justified, 1.5 spacing;
 * headings 16/14/12 bold; figure captions centred below, table captions centred above, bold 12;
 * page numbers bottom centre: none on the cover, roman from the certificate to the lists,
 * arabic from Chapter 1; IEEE numbered citations.
 *
 * Usage: node tools/build-docx.js university [--final]
 * Sources: <target>/NN-*.md in name order. Files named 00-*.md form the front matter.
 * Markdown subset: # / ## / ### / #### headings, paragraphs, - and 1. lists, pipe tables,
 * fenced code, ![Figure x.y: caption](figures/file.png), "Table x.y: caption" lines before a table,
 * **bold**, *italic*, `code`, [@key] citations, <!-- pagebreak -->, ::: center ... ::: blocks,
 * <!-- same-page --> (the next # heading does not start a new page) and <!-- table: plain --> (the next
 * table has no borders, shading or header row: signature grids), `<br>` inside table cells.
 */
const fs = require('fs');
const path = require('path');
const d = require('docx');

const target = process.argv[2] || 'university';
const final = process.argv.includes('--final');
const root = path.resolve(__dirname, '..');
const srcDir = path.join(root, target);

const FONT = 'Times New Roman';
const PT = (n) => n * 2; // half-points
const LINE_15 = 360;

// ---------- load sources ----------
const files = fs.readdirSync(srcDir).filter((f) => /^\d\d-.*\.md$/.test(f)).sort();
if (!files.length) { console.error(`no sources in ${srcDir}`); process.exit(1); }
const refs = JSON.parse(fs.readFileSync(path.join(srcDir, 'references.json'), 'utf8'));
const meta = JSON.parse(fs.readFileSync(path.join(srcDir, 'metadata.json'), 'utf8'));
// Where the .docx goes: `output_dir` in metadata.json (relative to report/), else report/out.
const outDir = path.resolve(root, meta.output_dir || 'out');
fs.mkdirSync(outDir, { recursive: true });

let text = {};
for (const f of files) text[f] = fs.readFileSync(path.join(srcDir, f), 'utf8');

// substitute {{meta.key}} placeholders
for (const f of files) text[f] = text[f].replace(/\{\{(\w+)\}\}/g, (_, k) => meta[k] ?? `<<${k}>>`);

const unfilled = [...new Set(files.flatMap((f) => text[f].match(/<<[^>]+>>/g) || []))];
if (unfilled.length) {
  const msg = `unfilled fields: ${unfilled.slice(0, 12).join(', ')}${unfilled.length > 12 ? ' …' : ''}`;
  if (final) { console.error(msg); process.exit(1); } else console.warn(`draft: ${msg}`);
}

// ---------- citations (IEEE order of first appearance) ----------
const citeOrder = [];
const allBody = files.map((f) => text[f]).join('\n');
for (const m of allBody.matchAll(/\[@([^\]]+)\]/g)) {
  for (const k of m[1].split(/;\s*@?/)) {
    const key = k.trim().replace(/^@/, '');
    if (!refs[key]) { console.error(`missing reference key: ${key}`); process.exit(1); }
    if (!citeOrder.includes(key)) citeOrder.push(key);
  }
}
const citeNum = (key) => citeOrder.indexOf(key) + 1;

// ---------- inline parsing ----------
function runs(str, base = {}) {
  str = str.replace(/\[@([^\]]+)\]/g, (_, ks) =>
    '[' + ks.split(/;\s*@?/).map((k) => citeNum(k.trim().replace(/^@/, ''))).join(', ') + ']');
  const out = [];
  const re = /(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`)/g;
  let last = 0; let m;
  const push = (t, o = {}) => t && out.push(new d.TextRun({ text: t, font: FONT, size: PT(12), ...base, ...o }));
  while ((m = re.exec(str))) {
    push(str.slice(last, m.index));
    const tok = m[0];
    if (tok.startsWith('**')) push(tok.slice(2, -2), { bold: true });
    else if (tok.startsWith('`')) push(tok.slice(1, -1), { font: 'Courier New', size: PT(10) });
    else push(tok.slice(1, -1), { italics: true });
    last = m.index + tok.length;
  }
  push(str.slice(last));
  return out;
}

const para = (children, opts = {}) => new d.Paragraph({
  children, alignment: d.AlignmentType.JUSTIFIED,
  spacing: { line: LINE_15, after: 120 }, ...opts,
});

// ---------- numbering (lists) ----------
const numbering = {
  config: [
    { reference: 'bullets', levels: [{ level: 0, format: d.LevelFormat.BULLET, text: '•', alignment: d.AlignmentType.LEFT,
      style: { paragraph: { indent: { left: 720, hanging: 360 } } } }] },
    ...Array.from({ length: 60 }, (_, i) => ({
      reference: `num${i}`, levels: [{ level: 0, format: d.LevelFormat.DECIMAL, text: '%1.', alignment: d.AlignmentType.LEFT,
        style: { paragraph: { indent: { left: 720, hanging: 360 } } } }],
    })),
  ],
};
let numCounter = 0;

// ---------- figures and tables ----------
const figures = []; const tables = [];
const CONTENT_WIDTH = 11906 - 1800 - 1440; // A4 width minus margins (DXA)

function imageBlock(caption, target) {
  // `![](figures/tu-logo.png =110)`: an optional width in pixels after the path.
  const [rel, size] = target.split(/\s+=/);
  const file = path.join(srcDir, rel);
  const blocks = [];
  if (fs.existsSync(file) && /\.(png|jpe?g)$/i.test(file)) {
    const buf = fs.readFileSync(file);
    let w = 600, h = 400;
    if (/\.png$/i.test(file)) { w = buf.readUInt32BE(16); h = buf.readUInt32BE(20); }
    const maxW = size ? Number(size) : 560; const maxH = 760; const scale = Math.min(maxW / w, maxH / h, size ? Infinity : 1);
    blocks.push(new d.Paragraph({ alignment: d.AlignmentType.CENTER, keepNext: true, children: [
      new d.ImageRun({ type: /\.png$/i.test(file) ? 'png' : 'jpg', data: buf,
        transformation: { width: Math.round(w * scale), height: Math.round(h * scale) } })] }));
  } else {
    blocks.push(new d.Paragraph({
      alignment: d.AlignmentType.CENTER, keepNext: true, spacing: { before: 120, after: 60 },
      // top/bottom only: docx 9 writes pBdr children in an order that fails schema validation when left/right are set
      border: { top: { style: d.BorderStyle.DASHED, size: 6, color: '888888' }, bottom: { style: d.BorderStyle.DASHED, size: 6, color: '888888' } },
      children: [new d.TextRun({ text: `[Figure to be inserted: ${rel}]`, font: FONT, size: PT(11), italics: true, color: '555555' })],
    }));
  }
  // An image without a caption (the university logo on the cover) is not a figure: no caption, no list entry.
  if (!caption) return blocks;
  figures.push(caption);
  blocks.push(new d.Paragraph({ style: 'FigureCaption', alignment: d.AlignmentType.CENTER, spacing: { after: 240 },
    children: runs(caption, { bold: true }) }));
  return blocks;
}

function tableBlock(rows, { plain = false } = {}) {
  const cells = rows.map((r) => r.replace(/^\||\|$/g, '').split('|').map((c) => c.trim()));
  // A plain table (signature grid) has no header row: every row is body, centred, without lines.
  const header = cells[0];
  const body = plain ? [cells[0], ...cells.slice(2)] : cells.slice(2);
  const n = header.length;
  // Column widths follow the longest text in each column (a narrow "S.N." next to a wide "Test"), each
  // at least an eighth of the width, so wide tables stay readable without horizontal squeeze.
  const textOf = (c) => c.replace(/\*\*|`|<br>/g, ' ');
  const longest = Array.from({ length: n }, (_, k) => Math.max(4, ...cells.filter((r, idx) => idx !== 1).map((r) =>
    Math.max(...textOf(r[k] || '').split(' ').map((w) => w.length), Math.min(textOf(r[k] || '').length, 40)))));
  const total = longest.reduce((a, b) => a + b, 0);
  const widths = longest.map((l) => Math.max(Math.floor(CONTENT_WIDTH / 8), Math.floor((CONTENT_WIDTH * l) / total)));
  const scaleW = CONTENT_WIDTH / widths.reduce((a, b) => a + b, 0);
  for (let k = 0; k < n; k++) widths[k] = Math.floor(widths[k] * scaleW);
  const colW = Math.floor(CONTENT_WIDTH / n);
  const border = plain ? { style: d.BorderStyle.NONE, size: 0, color: 'FFFFFF' } : { style: d.BorderStyle.SINGLE, size: 4, color: '000000' };
  const mk = (row, isHead) => new d.TableRow({ tableHeader: isHead, cantSplit: true, children: row.map((c, k) =>
    new d.TableCell({
      width: { size: widths[k], type: d.WidthType.DXA },
      borders: { top: border, left: border, bottom: border, right: border },
      shading: isHead ? { type: d.ShadingType.CLEAR, fill: 'E7E6E6', color: 'auto' } : undefined,
      margins: { top: 40, bottom: 40, left: 80, right: 80 },
      // `<br>` splits a cell into lines (the signature blocks of the approval letter).
      children: c.split(/<br>/).map((line) => new d.Paragraph({ alignment: plain ? d.AlignmentType.CENTER : undefined,
        children: runs(line.trim(), { size: PT(plain ? 12 : 11), bold: isHead || undefined }), spacing: { line: 276 } })),
    })) });
  void colW;
  return new d.Table({ width: { size: widths.reduce((a, b) => a + b, 0), type: d.WidthType.DXA }, columnWidths: widths,
    alignment: d.AlignmentType.CENTER, rows: [...(plain ? [] : [mk(header, true)]), ...body.filter((r) => r.length === n || r.length > 0).map((r) => {
      while (r.length < n) r.push(''); return mk(r.slice(0, n), false); })] });
}

// ---------- block parsing ----------
function blocksFrom(md, { chapterBreaks = true } = {}) {
  const lines = md.split('\n');
  const out = [];
  let i = 0; let firstH1 = true; let center = false; let samePage = false; let plainTable = false;
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    if (line.trim() === '<!-- pagebreak -->') { out.push(new d.Paragraph({ children: [new d.PageBreak()] })); i++; continue; }
    if (line.startsWith('::: center')) { center = true; i++; continue; }
    if (line.startsWith(':::')) { center = false; i++; continue; }
    if (line.trim() === '<!-- same-page -->') { samePage = true; i++; continue; }
    if (line.trim() === '<!-- table: plain -->') { plainTable = true; i++; continue; }
    if (line.startsWith('<!--')) { while (i < lines.length && !lines[i].includes('-->')) i++; i++; continue; }
    const h = line.match(/^(#{1,4}) (.*)$/);
    if (h) {
      const level = h[1].length;
      const size = [16, 14, 12, 12][level - 1];
      const heading = [d.HeadingLevel.HEADING_1, d.HeadingLevel.HEADING_2, d.HeadingLevel.HEADING_3, d.HeadingLevel.HEADING_4][level - 1];
      out.push(new d.Paragraph({
        heading, keepNext: true, pageBreakBefore: level === 1 && chapterBreaks && !firstH1 && !samePage,
        alignment: level === 1 ? d.AlignmentType.CENTER : d.AlignmentType.LEFT,
        spacing: { before: level === 1 ? 0 : 240, after: 120, line: LINE_15 },
        children: [new d.TextRun({ text: h[2], font: FONT, size: PT(size), bold: true, color: '000000' })],
      }));
      if (level === 1) { firstH1 = false; samePage = false; }
      i++; continue;
    }
    if (line.startsWith('```')) {
      const code = []; i++;
      while (i < lines.length && !lines[i].startsWith('```')) code.push(lines[i++]);
      i++;
      for (const c of code) out.push(new d.Paragraph({ spacing: { line: 240, after: 0 }, indent: { left: 360 },
        children: [new d.TextRun({ text: c || ' ', font: 'Courier New', size: PT(9) })] }));
      out.push(new d.Paragraph({ spacing: { after: 120 }, children: [] }));
      continue;
    }
    const img = line.match(/^!\[([^\]]*)\]\(([^)]+)\)\s*$/);
    if (img) { out.push(...imageBlock(img[1], img[2])); i++; continue; }
    if (/^Table ([A-Z]\.)?\d+(\.\d+)?:/.test(line.trim())) {
      tables.push(line.trim());
      out.push(new d.Paragraph({ style: 'TableCaption', alignment: d.AlignmentType.CENTER, keepNext: true, spacing: { before: 120, after: 60 },
        children: runs(line.trim(), { bold: true }) }));
      i++; continue;
    }
    if (line.startsWith('|')) {
      const rows = [];
      while (i < lines.length && lines[i].startsWith('|')) rows.push(lines[i++]);
      out.push(tableBlock(rows, { plain: plainTable }));
      plainTable = false;
      out.push(new d.Paragraph({ spacing: { after: 120 }, children: [] }));
      continue;
    }
    if (/^\s*- /.test(line)) {
      while (i < lines.length && /^\s*- /.test(lines[i])) {
        out.push(para(runs(lines[i].replace(/^\s*- /, '')), { numbering: { reference: 'bullets', level: 0 }, spacing: { line: LINE_15, after: 40 } }));
        i++;
      }
      continue;
    }
    if (/^\d+\. /.test(line)) {
      const ref = `num${numCounter++ % 60}`;
      while (i < lines.length && /^\d+\. /.test(lines[i])) {
        out.push(para(runs(lines[i].replace(/^\d+\. /, '')), { numbering: { reference: ref, level: 0 }, spacing: { line: LINE_15, after: 40 } }));
        i++;
      }
      continue;
    }
    const buf = [];
    while (i < lines.length && lines[i].trim() && !/^(#|```|\||!\[|\s*- |\d+\. |:::|<!--)/.test(lines[i]) && !/^Table ([A-Z]\.)?\d+(\.\d+)?:/.test(lines[i].trim())) buf.push(lines[i++].trim());
    out.push(para(runs(buf.join(' ')), center ? { alignment: d.AlignmentType.CENTER } : {}));
  }
  return out;
}

// ---------- assemble ----------
const front = files.filter((f) => f.startsWith('00-'));
const main = files.filter((f) => !f.startsWith('00-') && !f.startsWith('9'));
const appendixFiles = files.filter((f) => f.startsWith('9'));
const coverFile = front.find((f) => f.includes('cover'));
const preFiles = front.filter((f) => f !== coverFile);

const mainBlocks = main.flatMap((f) => blocksFrom(text[f]));
// references
mainBlocks.push(new d.Paragraph({ heading: d.HeadingLevel.HEADING_1, pageBreakBefore: true, alignment: d.AlignmentType.CENTER,
  children: [new d.TextRun({ text: 'References', font: FONT, size: PT(16), bold: true, color: '000000' })] }));
citeOrder.forEach((k, idx) => mainBlocks.push(new d.Paragraph({
  spacing: { line: LINE_15, after: 80 }, indent: { left: 540, hanging: 540 }, alignment: d.AlignmentType.LEFT,
  children: [new d.TextRun({ text: `[${idx + 1}]\t`, font: FONT, size: PT(12) }), ...runs(refs[k])] })));
const unused = Object.keys(refs).filter((k) => !citeOrder.includes(k));
if (unused.length) {
  mainBlocks.push(new d.Paragraph({ heading: d.HeadingLevel.HEADING_1, pageBreakBefore: true, alignment: d.AlignmentType.CENTER,
    children: [new d.TextRun({ text: 'Bibliography', font: FONT, size: PT(16), bold: true, color: '000000' })] }));
  unused.forEach((k) => mainBlocks.push(new d.Paragraph({ spacing: { line: LINE_15, after: 80 }, indent: { left: 540, hanging: 540 },
    children: runs(refs[k]) })));
}

for (const f of appendixFiles) mainBlocks.push(new d.Paragraph({ children: [new d.PageBreak()] }), ...blocksFrom(text[f]));
const preBlocks = preFiles.flatMap((f) => blocksFrom(text[f]));
const listHeading = (t) => new d.Paragraph({ heading: d.HeadingLevel.HEADING_1, pageBreakBefore: true, alignment: d.AlignmentType.CENTER,
  children: [new d.TextRun({ text: t, font: FONT, size: PT(16), bold: true, color: '000000' })] });
preBlocks.push(listHeading('Table of Contents'));
preBlocks.push(new d.TableOfContents('Table of Contents', { hyperlink: true, headingStyleRange: '1-3' }));
// No on-page hint to update fields: `updateFields` makes Word offer it on opening, and the hint would print.
// The lists are Word fields over the caption styles, like the table of contents, so they carry page
// numbers once Word updates the fields on opening (as the old reports' lists do).
if (figures.length) {
  preBlocks.push(listHeading('List of Figures'));
  preBlocks.push(new d.TableOfContents('List of Figures', { hyperlink: true,
    stylesWithLevels: [new d.StyleLevel('Figure Caption', 1)] }));
}
if (tables.length) {
  preBlocks.push(listHeading('List of Tables'));
  preBlocks.push(new d.TableOfContents('List of Tables', { hyperlink: true,
    stylesWithLevels: [new d.StyleLevel('Table Caption', 1)] }));
}
const abbrevFile = path.join(srcDir, 'abbreviations.md');
if (fs.existsSync(abbrevFile)) {
  preBlocks.push(listHeading('List of Abbreviations'));
  preBlocks.push(...blocksFrom(fs.readFileSync(abbrevFile, 'utf8').replace(/^# .*\n/, ''), { chapterBreaks: false }));
}

const pageProps = (fmt, start) => ({
  page: { size: { width: 11906, height: 16838 }, margin: { top: 1440, bottom: 1440, right: 1440, left: 1800, footer: 708 },
    pageNumbers: fmt ? { start, formatType: fmt } : undefined },
});
const footer = () => ({ default: new d.Footer({ children: [new d.Paragraph({ alignment: d.AlignmentType.CENTER,
  children: [new d.TextRun({ children: [d.PageNumber.CURRENT], font: FONT, size: PT(12) })] })] }) });

const sections = [];
if (coverFile) sections.push({ properties: pageProps(null), children: blocksFrom(text[coverFile], { chapterBreaks: false }) });
sections.push({ properties: pageProps(d.NumberFormat.LOWER_ROMAN, 1), footers: footer(), children: preBlocks });
sections.push({ properties: pageProps(d.NumberFormat.DECIMAL, 1), footers: footer(), children: mainBlocks });

const doc = new d.Document({
  creator: meta.author || 'Smart Helpdesk',
  title: meta.title,
  features: { updateFields: true },
  numbering,
  styles: {
    default: { document: { run: { font: FONT, size: PT(12) }, paragraph: { spacing: { line: LINE_15 } } } },
    paragraphStyles: [
      { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { font: FONT, size: PT(16), bold: true, color: '000000' }, paragraph: { outlineLevel: 0 } },
      { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { font: FONT, size: PT(14), bold: true, color: '000000' }, paragraph: { outlineLevel: 1 } },
      { id: 'Heading3', name: 'Heading 3', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { font: FONT, size: PT(12), bold: true, color: '000000' }, paragraph: { outlineLevel: 2 } },
      { id: 'Heading4', name: 'Heading 4', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { font: FONT, size: PT(12), bold: true, italics: true, color: '000000' }, paragraph: { outlineLevel: 3 } },
      // Captions: bold 12, centred (figure below, table above). Their own styles feed the two lists.
      { id: 'FigureCaption', name: 'Figure Caption', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { font: FONT, size: PT(12), bold: true, color: '000000' }, paragraph: { alignment: d.AlignmentType.CENTER } },
      { id: 'TableCaption', name: 'Table Caption', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { font: FONT, size: PT(12), bold: true, color: '000000' }, paragraph: { alignment: d.AlignmentType.CENTER } },
    ],
  },
  sections,
});

const outFile = path.join(outDir, `${meta.output || target}.docx`);
d.Packer.toBuffer(doc).then((buf) => {
  fs.writeFileSync(outFile, buf);
  console.log(`built ${path.relative(root, outFile)}: ${figures.length} figures, ${tables.length} tables, ${citeOrder.length} references`);
});
