# Report generation pipeline

Produces the CACS452 Project III report (DOCX) from Markdown sources in `report/`, enforcing the CACS452 format standards. The platform documentation in `docs/` is a separate artefact and is never generated from, or into, the report.

## Sources

```text
report/
├── university/
│   ├── 00-cover.md             cover page (no page number)
│   ├── 00-front-matter.md      recommendation, approval letter, acknowledgement, abstract
│   ├── 01-introduction.md … 05-conclusion.md
│   ├── 90-appendices.md        placed after References and Bibliography
│   ├── abbreviations.md        → List of Abbreviations
│   ├── references.json         key → IEEE-formatted entry; cited with [@key]
│   ├── metadata.json           names, dates, college; replaces {{key}} placeholders
│   └── figures/                manifest.yaml, exported diagrams, screenshots, plots
├── tools/
│   ├── build-docx.js           Markdown subset → DOCX with the CACS452 format rules
│   └── export-figures.js       extracts Mermaid blocks from ../docs; renders with mmdc if installed
├── build.sh                    wrapper: ./build.sh [--final]
└── (output)                  ../college/report/Project-III-Report-Smart-Helpdesk.docx (metadata `output`, `output_dir`)
```

## Tooling

| Tool | Use | Status |
|---|---|---|
| Node 22+ with `docx` 9.7 (npm, local to `report/`) | builds the DOCX: sections with no/roman/arabic page numbers, A4 margins, styles, captions, tables, TOC field, IEEE numbering | installed; chosen because pandoc is not available on the development machine and `docx` expresses per-section page numbering directly |
| `@mermaid-js/mermaid-cli` (npm, local to `report/`) | renders exported `.mmd` diagrams to PNG | installed; uses the system Google Chrome via `report/puppeteer.json` |
| matplotlib | experiment plots | produced by `experiments/plots.py` |
| LibreOffice | DOCX → PDF for the soft copy and visual checks | optional, not installed on the planning machine |

## Build steps

1. `./tools/render-diagrams.sh` renders the report's own diagrams (since the A4 revision they are simplified from the docs diagrams and no longer exported from `docs/`): Mermaid sources with `figures/mermaid.config.json`, the use case diagrams (`tools/usecase-diagrams.py`), the Gantt chart (`tools/gantt-diagram.py`) and the waterfall model (`tools/waterfall-diagram.py`) as SVG rendered by `tools/render-svg.js`; the script reports the printed text size of each. Screenshots (`app-*.png`) come from the demonstration workspace.
2. `./build.sh` substitutes metadata, numbers citations in order of first use, builds front matter with lower-roman page numbers and chapters with arabic numbers, generates the lists of figures, tables and abbreviations, appends References, Bibliography (uncited entries) and Appendices.
3. Missing figure files render as labelled placeholders; `--final` refuses to build while any `<<field>>` remains.
4. `./tools/paginate.sh` lays the document out with LibreOffice (Docker image from `tools/lo`), records the page of every heading and caption in `university/pages.json` (`tools/measure-pages.py`) and rebuilds until stable, so the contents and lists are written out with page numbers and show in every editor.
5. Validation: the generated files pass the Office Open XML schema check used during development.

## Writing rules for the report

- Past tense, impersonal in the chapters ("the system computes…"; the acknowledgement speaks for both authors), contextualised to Smart Helpdesk (the syllabus forbids basic definitions).
- Every figure and table captioned (`Figure 3.4: …` below figures, `Table 4.2: …` above tables; appendices use their letter, `Figure C.1`) and referenced in text before it appears. Captions carry the `Figure Caption` and `Table Caption` styles that feed the lists.
- Layout directives: `<!-- pagebreak -->`, `<!-- same-page -->` (next chapter-level heading stays on the page), `<!-- table: plain -->` (borderless signature table), `::: center`.
- Cite with `[@runeson2007]`; the builder numbers references in IEEE order of first citation.
- The university report describes testing as manual testing and gives no experiment data (owner decision, 2026-09-25); the experiments remain in `experiments/`.
- Screenshots follow the demo script order, light theme, 1440 px wide, PNG.
- Abbreviations are added to `abbreviations.md` on first use.

## Status

Final: `./tools/paginate.sh --final` builds `college/report/Project-III-Report-Smart-Helpdesk.docx`, 55 pages (34 figures, 11 tables, 8 references). The `college/` folder also holds the syllabus and the earlier project reports used for structure only.
