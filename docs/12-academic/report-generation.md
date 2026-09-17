# Report generation pipeline

Produces the university report (DOCX) and the internship report (DOCX) from Markdown sources in `report/`, enforcing the CACS452 format standards. The platform documentation in `docs/` is a separate artefact and is never generated from, or into, the report.

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
├── internship/                 same layout, basic template
├── tools/
│   ├── build-docx.js           Markdown subset → DOCX with the CACS452 format rules
│   └── export-figures.js       extracts Mermaid blocks from ../docs; renders with mmdc if installed
├── build.sh                    wrapper: ./build.sh [university|internship|all] [--final]
└── out/                        generated .docx (git-ignored)
```

## Tooling

| Tool | Use | Status |
|---|---|---|
| Node 22+ with `docx` 9.7 (npm, local to `report/`) | builds the DOCX: sections with no/roman/arabic page numbers, A4 margins, styles, captions, tables, TOC field, IEEE numbering | installed; chosen because pandoc is not available on the development machine and `docx` expresses per-section page numbering directly |
| `@mermaid-js/mermaid-cli` (npm, local to `report/`) | renders exported `.mmd` diagrams to PNG | installed; uses the system Google Chrome via `report/puppeteer.json` |
| matplotlib | experiment plots | produced by `experiments/plots.py` |
| LibreOffice | DOCX → PDF for the soft copy and visual checks | optional, not installed on the planning machine |

## Build steps

1. `node tools/export-figures.js` writes each manifest diagram to `figures/<id>.mmd` (and PNG when `mmdc` exists); screenshots and plots are copied into `figures/` by their tasks.
2. `./build.sh` substitutes metadata, numbers citations in order of first use, builds front matter with lower-roman page numbers and chapters with arabic numbers, generates the lists of figures, tables and abbreviations, appends References, Bibliography (uncited entries) and Appendices.
3. Missing figure files render as labelled placeholders; `--final` refuses to build while any `<<field>>` remains.
4. Validation: the generated files pass the Office Open XML schema check used during development.

## Writing rules for the report

- Past tense, first person plural avoided ("the system computes…"), contextualised to Smart Helpdesk (the syllabus forbids basic definitions).
- Every figure and table captioned (`Figure 3.4: …` below figures, `Table 4.2: …` above tables) and referenced in text before it appears.
- Cite with `[@runeson2007]`; the builder numbers references in IEEE order of first citation.
- Numbers come from `experiments/results/` and CI reports; never typed by hand.
- Screenshots follow the demo script order, light theme, 1440 px wide, PNG.
- Abbreviations are added to `abbreviations.md` on first use.

## Draft status

First drafts exist: `out/smart-helpdesk-project-report.docx` (Chapters 1–3 complete with 19 rendered diagrams, Chapter 4 tables with pending cells, Chapter 5 drafted, front-matter fields open) and `out/internship-report.docx` (template with generic prose). Rebuild after every milestone so the supervisor can review early.

## Internship report

A basic internship report is prepared under `report/internship/` following the common TU BCA internship structure (introduction; organisation details and literature review; internship activities with weekly log; conclusion and learning outcomes). Check it against the department's internship guideline, which was not provided. The following inputs are needed from the owner before it can be completed: organisation name and address, supervisor names (organisation and college), internship duration and dates, the internship course code and any prescribed format. Until then the draft uses `<<field>>` markers and reuses the technology and module descriptions of this project as the "work performed" material only where the internship actually concerned this system.
