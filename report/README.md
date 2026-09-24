# Report sources

The CACS452 Project III report (Word) is built from this folder, separately from the platform documentation in `../docs/`:

| Document | Sources | Output |
|---|---|---|
| CACS452 Project III report | `university/` | `out/smart-helpdesk-project-report.docx` |

Pipeline rules and the chapter mapping: `../docs/12-academic/report-generation.md`, `../docs/12-academic/report-mapping.md`.

## Build

```bash
cd report
./build.sh                 # the report (draft: warns about unfilled <<fields>>)
./build.sh university --final   # refuses to build while any <<field>> remains
node tools/export-figures.js    # extract Mermaid diagrams from ../docs into university/figures/*.mmd (renders PNG if mmdc is installed)
```

Requirements: Node 22+ (`docx` and `@mermaid-js/mermaid-cli` are installed locally by `npm install`). Diagrams are rendered with the system Google Chrome configured in `puppeteer.json`, so no browser download is needed. Optional: LibreOffice for PDF export (`soffice --headless --convert-to pdf out/*.docx`).

## What the builder enforces (CACS452 format standards)

A4; margins top/bottom/right 1", left 1.25"; Times New Roman 12, justified, 1.5 line spacing; headings 16/14/12 bold; figure captions centred below figures, table captions centred above tables, bold 12; no page number on the cover, lower-roman numbers from the recommendation page to the lists, arabic numbers from Chapter 1, bottom centre; IEEE numbered citations in order of first use; References, then Bibliography (uncited entries), then Appendices. The table of contents is a Word field: Word asks to update fields on opening; accept, or right-click the table and choose *Update Field*.

## Source conventions

- `00-cover.md` has no page number; other `00-*.md` files are front matter; `01`–`05` are chapters; `9x` files are appendices.
- `metadata.json` values replace `{{key}}` placeholders. Anything still written as `<<…>>` is a field for the author to fill.
- Cite with `[@key]` using keys from `references.json` (IEEE-formatted strings).
- Figures: `![Figure 3.1: Caption](figures/file.png)`. A missing file renders as a labelled placeholder so drafts still build.
- Tables: a line `Table 3.1: Caption` directly before a pipe table.
- `<!-- pagebreak -->` forces a page break; `::: center` … `:::` centres a block.

## Status

Chapters 1–3 are complete first drafts written from the planning documents. Chapter 4 contains the tools table, module outline and all test-case and result tables with `<<pending>>` cells to be filled from test reports and `../experiments/results/`. Chapter 5 is drafted and needs the measured results. Front-matter names, dates and signatures are fields. All 19 diagrams are rendered from the Mermaid sources in `../docs`; screenshots and experiment plots remain placeholders until the application and experiments exist. Figures are scaled to fit the A4 content area.
