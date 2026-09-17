# University requirements (CACS452 Project III)

Source: the scanned syllabus `CAPJ452-Project-III.pdf` in the repository root (Tribhuvan University BCA, Project III, 6 credits, year IV semester VIII, 12 practical hours/week). This page transcribes the requirements that bind the project and maps each to the artefact that satisfies it. The syllabus timetable (proposal after week 3, midterm in week 12, final in the last week) is not used for planning; the owner has stated that the schedule is already past those phases and the build should finish as fast as possible.

## Nature of the project (verbatim intent)

- A **complete functioning system**, not limited to basic CRUD.
- Students are "highly recommended to implement appropriate algorithms relevant to the project".
- "Precise system analysis, design, implementation and result analysis."
- Students "should be able to write their own program modules rather than relying on predefined APIs or Plugins except in some unavoidable circumstances."
- Group of at most two; any technology.

Our reading of "own program modules": the four algorithms, the ticket and SLA state machines, the tenancy isolation layer, the webhook delivery mechanism and the media/mail pipelines are written by us; frameworks (Laravel, React), the database and infrastructure are the "unavoidable" foundations and are declared as such in [algorithm-contribution.md](algorithm-contribution.md).

## Evaluation scheme

| Component | Marks | Evaluated by | Criteria (verbatim headings) |
|---|---|---|---|
| Proposal defence | 10 | research committee (HoD/coordinator) | proposal and its presentation |
| Midterm | 70 | supervisor 60 + internal examiner 10 | **Work done 60 %**: system analysis and design; implementation; understanding of methods used; ability to work with others; ability to identify problems; amount of work performed. **Documentation 10 %**: report organisation; writing style; completeness; readability; organisation and analysis of data and results |
| Final defence | 20 | external examiner | presentation, demonstration ("the project should be ready to run for the demo session"), viva |

Internal (80) and external (20) must each be passed individually. Focus of evaluation: presentation skills, project demonstration, project report, viva, level of work and understanding, teamwork and contribution.

Implications for this project: the **midterm work-done criteria are where marks concentrate**, so diagrams, algorithm explanations and measured results must exist early; the demo must run offline ([demo-plan.md](demo-plan.md)); "amount of work performed" rewards the breadth of modules, which the roadmap tracks.

## Prescribed report structure

Front matter (roman numerals): cover and title page; certificate page (supervisor recommendation; internal and external examiners' approval letter); acknowledgement; abstract; table of contents; list of abbreviations, list of figures, list of tables. Main report (arabic numerals from 1); references (IEEE); bibliography (if any); appendices (screenshots, source code).

| Chapter | Sections (verbatim) | Artefacts feeding it |
|---|---|---|
| 1 Introduction | 1.1 Introduction · 1.2 Problem Statement · 1.3 Objectives · 1.4 Scope and Limitation · 1.5 Development Methodology · 1.6 Report Organization | [vision](../00-project/vision.md), [goals](../00-project/goals.md), [scope](../00-project/scope.md), [mvp-scope](../02-product/mvp-scope.md), roadmap |
| 2 Background Study and Literature Review | 2.1 Background Study (fundamental theories, concepts, terminology) · 2.2 Literature Review (similar projects, theories, results by other researchers) | [terminology](../00-project/terminology.md), [findings](../01-research/findings.md), algorithm pages §Background, related systems table |
| 3 System Analysis and Design | 3.1 System Analysis: 3.1.1 Requirement Analysis (functional with use-case diagram and descriptions; non-functional) · 3.1.2 Feasibility (technical, operational, economic, schedule) · 3.1.3 Object modelling (class and object diagrams) · 3.1.4 Dynamic modelling (state and sequence diagrams) · 3.1.5 Process modelling (activity diagrams) · 3.2 System Design: 3.2.1 refinement of the diagrams · 3.2.2 component diagrams · 3.2.3 deployment diagrams · 3.3 Algorithm Details | [use-cases](../02-product/use-cases.md), [requirements](../02-product/functional-requirements.md), [entities](../08-database/entities.md), [backend](../03-architecture/backend.md), [user-flows](../02-product/user-flows.md), [tickets](../04-domain/tickets.md), [diagrams](../03-architecture/diagrams.md), [overview](../03-architecture/overview.md), [deployment](../03-architecture/deployment.md), [05-algorithms](../05-algorithms/priority-scoring.md) |
| 4 Implementation and Testing | 4.1 Implementation: 4.1.1 Tools Used (CASE tools, languages, database platforms) · 4.1.2 Implementation Details of Modules (classes/procedures/functions/methods/algorithms) · 4.2 Testing: 4.2.1 Test Cases for Unit Testing · 4.2.2 Test Cases for System Testing · 4.3 Result Analysis | [versions](../01-research/versions.md), module code, [testing](../10-quality/testing.md), [result-analysis-plan](result-analysis-plan.md) |
| 5 Conclusion and Future Recommendations | 5.1 Conclusion · 5.2 Future Recommendations | goals outcome, [v1-backlog](../../roadmap/09-v1-backlog.md) |

"Students should avoid basic definitions. They should relate and contextualize the above mentioned concepts with their project work." The report therefore explains concepts only as applied to Smart Helpdesk.

Proposal content flow (for completeness): introduction, problem statement, objectives, methodology (requirement identification: existing system, literature review, requirement analysis; feasibility: technical, operational, economic; high-level design: flow chart / working mechanism / algorithms), Gantt chart, expected outcome, references.

## Format standards

| Item | Rule |
|---|---|
| Paper | A4; margins top 1", bottom 1", right 1", left 1.25" |
| Paragraphs | justified, 1.5 line spacing, Times New Roman 12 |
| Headings | chapter 16 bold, section 14 bold, sub-section 12 bold |
| Figures and tables | centred; figure caption centred below, table caption centred above, captions bold 12 |
| Page numbers | bottom centre; roman from certificate page to lists; arabic from chapter 1 |
| Citations | IEEE style in text and reference list; uncited sources go to bibliography |
| Submission | 3 copies (library, self, dean office); golden embossing, black binding; signed copy to Dean Office, Exam Section, FOHSS; report to the department ≥ 10 days before the defence; copy to the external expert ≥ 1 week before |

The generation pipeline that enforces these rules is in [report-generation.md](report-generation.md).

## Development methodology to declare

Incremental development in dependency-ordered milestones (adapted iterative model), each ending in a working, tested system ([roadmap](../../roadmap/README.md)); ADRs record design decisions per increment. Not Scrum (solo or pair).

## Feasibility inputs

- **Technical**: open-source stack running on one VM; evidence is the Compose stack.
- **Operational**: personas and use cases ([02-product](../02-product/personas.md)); demo.
- **Economic**: zero licence cost; VM cost table in [terraform.md](../09-infrastructure/terraform.md).
- **Schedule**: milestone plan in the roadmap; the report's schedule feasibility shows the actual dates once known.
