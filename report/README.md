# Report sources

The CACS452 Project III report (Word) is built from this folder, separately from the platform documentation in `../docs/`:

| Document | Sources | Output |
|---|---|---|
| CACS452 Project III report | `university/` | `../college/report/Project-III-Report-Smart-Helpdesk.docx` (set by `output` and `output_dir` in `metadata.json`) |

Pipeline rules and the chapter mapping: `../docs/12-academic/report-generation.md`, `../docs/12-academic/report-mapping.md`.

## Build

```bash
cd report
./build.sh                 # the report (draft: warns about unfilled <<fields>>)
./build.sh university --final   # refuses to build while any <<field>> remains
./tools/render-diagrams.sh      # render the report's own diagrams for A4 (Mermaid with mermaid.config.json, plus the SVG use case and Gantt charts)
docker build -t shp-report-lo tools/lo   # once: LibreOffice for layout
./tools/paginate.sh --final     # build, lay out with LibreOffice, fill the contents and lists with page numbers (pages.json), print the page count
```

The table of contents, List of Figures and List of Tables are written out in full from `university/pages.json`, so OnlyOffice and LibreOffice show them without updating fields; the page numbers come from a LibreOffice layout (Liberation Serif, metric-compatible with Times New Roman) and may differ by a page in other editors. Rerun `./tools/paginate.sh` after any change. Without `pages.json` the builder writes Word fields instead.

Edits made directly in the .docx (for example in OnlyOffice) are lost on the next build: carry them into the Markdown first (compare the text of the edited file with a fresh build).

Requirements: Node 22+ (`docx` and `@mermaid-js/mermaid-cli` are installed locally by `npm install`). Diagrams are rendered with the system Google Chrome configured in `puppeteer.json`, so no browser download is needed. Optional: LibreOffice for PDF export (`soffice --headless --convert-to pdf ../college/report/*.docx`).

## What the builder enforces (CACS452 format standards)

A4; margins top/bottom/right 1", left 1.25"; Times New Roman 12, justified, 1.5 line spacing; headings 16/14/12 bold; figure captions centred below figures, table captions centred above tables, bold 12; no page number on the cover, lower-roman numbers from the recommendation page to the lists, arabic numbers from Chapter 1, bottom centre; IEEE numbered citations in order of first use; References, then Bibliography (uncited entries), then Appendices. The table of contents is a Word field: Word asks to update fields on opening; accept, or right-click the table and choose *Update Field*.

## Source conventions

- `00-cover.md` has no page number; other `00-*.md` files are front matter; `01`–`05` are chapters; `9x` files are appendices.
- `metadata.json` values replace `{{key}}` placeholders. Anything still written as `<<…>>` is a field for the author to fill.
- Cite with `[@key]` using keys from `references.json` (IEEE-formatted strings).
- Figures: `![Figure 3.1: Caption](figures/file.png)`, up to the full 6.25-inch text width; appendix figures use the appendix letter (`Figure C.1`). `![](figures/file.png =80)` is an uncaptioned image 80 px wide (the logo), left out of the List of Figures. A missing file renders as a labelled placeholder so drafts still build.
- Tables: a line `Table 3.1: Caption` (or `Table A.1: …`) directly before a pipe table. A paragraph may start with "Table 4.1 lists…"; only a label followed by a colon is a caption. `<br>` in a cell starts a new line.
- Captions use the `Figure Caption` and `Table Caption` styles, from which Word builds the List of Figures and List of Tables.
- `<!-- pagebreak -->` forces a page break; `<!-- same-page -->` keeps the next `#` heading on the current page (the recommendation and approval pages under the college header); `<!-- table: plain -->` draws the next table without borders or header (signature blocks); `::: center` … `:::` centres a block.

## Status

Final (2026-09-25), 55 pages in the LibreOffice layout: front matter with filled contents and lists, Chapters 1 to 5, eight references and screenshots in the appendix. The methodology is the waterfall model, testing is reported as manual testing, the schedule as three months (16 June to 15 September 2026), and the diagrams are the report's own versions sized for A4 (about 9 to 13 pt when printed).
