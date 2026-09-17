# Schedule and compression strategy

The syllabus timetable (proposal after week 3, midterm in week 12, final in the last week) no longer applies: the owner is past those phases and wants the whole roadmap built in **one week or less** through continuous development sessions. The owner is the only human developer; coding agents do the typing on parallel tracks and the owner reviews and merges. The roadmap therefore has **no fixed dates**. Everything that decides dates is in [schedule.yaml](schedule.yaml) and is recomputed by:

```bash
python3 roadmap/tools/schedule.py            # prints effort, finish date and a dated task table
```

## Settings

| Key | Meaning | Default |
|---|---|---|
| `start_date` | first working day | 2026-09-18 |
| `timezone` | informational | Asia/Kathmandu |
| `working_days` | weekday names that are worked | all seven (sprint) |
| `hours_per_day` | active development hours per day across sessions; 8 h = one effort-day | 16 |
| `shifts` | informational session windows | 06–14 and 15–23 |
| `holidays` | ISO dates skipped | none |
| `parallel_tracks` | concurrent coding-agent tracks (A backend, B frontend, C infra/mail/media/quality/academic, D algorithm cores and reporting) | 4 |
| `buffer_ratio` | buffer added after the last task | 0.15 |
| `size_days` | effort-days per size (XS 0.1, S 0.25, M 0.5, L 1, XL 1.5) | AI-assisted calibration |

Change any value and rerun; for example set `working_days` to all seven days, or `holidays` to exam dates.

## Computed results

| Variant | Working days | With buffer |
|---|---|---|
| **Sprint default: 4 tracks, 16 h/day, every day** | **6.6** | **7.6** |
| 4 tracks, 20 h/day | 5.3 | 6.1 |
| 4 tracks, 12 h/day | 8.8 | 10.2 |
| 3 tracks, 16 h/day | 8.6 | 9.9 |
| 1 track (strictly sequential), 10 h/day, six days a week | 28.6 | 32.8 |

Total effort is 35.7 effort-days across 57 tasks, including the reporting module and the minimal algorithm baselines (ADR-0023). With the sprint default all four tracks finish within a day of each other, so the plan is balanced; the remaining limit is the dependency chain tenancy → authentication → RBAC → ticket model → SLA integration → reporting read models → report catalogue → reports UI.

## How the compression works

1. **Four tracks with clear file ownership.** Track A owns `backend/app/Modules/{Platform,Tenancy,Identity,Contacts,Agents,Tickets,Integrations,Mail}` and the wiring of algorithms; track B owns `frontend/` except the reporting and entity-360 features; track C owns `infra/`, `backend/app/Modules/{Media,Notifications,Audit}`, `experiments/`, `report/`; track D owns the pure domain classes (`Sla/Domain`, `Automation/Domain`, `Reporting/Domain`), `backend/app/Modules/Reporting` and `frontend/src/features/reports`. Merge conflicts are limited to route files and migrations, which use per-module folders.
2. **Pure cores first.** The four algorithms and the history/interval logic have no database or HTTP dependencies, so track D writes and tests them from the first session while tenancy and the UI shell are built; Milestone 2 only wires them in.
3. **Contract first.** Track A publishes FormRequests and JsonResources before implementing behaviour, so the OpenAPI export and the generated TypeScript types exist early; track B builds against MSW mocks derived from them ([frontend.md](../docs/03-architecture/frontend.md)).
4. **Coding agents per track.** Each track runs in its own git worktree with the agent reading `CLAUDE.md` and exactly one task block; the owner reviews every merge against the [Definition of Done](../docs/10-quality/definition-of-done.md). Review time is included in the size calibration.
5. **Session integration.** Every session ends with all tracks merged to `main`, CI green, and the demo stack starting.
6. **Time boxes stay.** Table v9, Passport, OpenTofu and inbound mail have explicit fallbacks; applying a fallback is always cheaper than overrunning.
7. **Academic artefacts are continuous.** Diagrams, test tables and experiment outputs are produced by the tasks that create the code, so the report does not wait for the end.

## Honest limits

- The calibration assumes an AI agent writes most code and the owner reviews; if a size takes longer in practice, edit `size_days` after Milestone 1 and recompute.
- Critical-path tasks (tenancy, auth, RBAC, DataTable, ticket model, SLA, priority, assignment, ticket UI, duplicates, webhooks, E2E, demo) cannot be parallelised further without splitting them.
- Adding the mail server, media library and calendars added about 4 effort-days, and the reporting module about 6, to the original scope.
- 16 hours of development per day for a week is only realistic because agents type; the owner's review load is the real constraint. If reviews fall behind, lower `parallel_tracks` rather than merging unreviewed work.

## Decision points

| When | Decision | Trigger |
|---|---|---|
| End of M1-14 time box | TanStack Table v9 or v8 | DataTable not working after one track-day |
| End of M3-04 time box | Passport or Sanctum tokens | client-credentials flow not green |
| Before Milestone 3 | Reverb go/no-go | buffer consumed |
| End of M3-14 time box | OpenTofu apply or Ansible on a manual VM | provider apply not green |
| Before M3-19 | inbound email in or cut | more than half the buffer used |

## Session protocol (continuous development)

Each development session advances as many tasks as possible:

1. **Start:** pull `main`, run `python3 roadmap/tools/schedule.py --ready`, and pick one ready task per track, critical tasks first.
2. **Dispatch:** give each track's agent its own git worktree, the task block, `CLAUDE.md`, and the docs pages the task links to. Agents mark the task `[~]`.
3. **Work:** agents implement, test and update the named docs. The owner answers questions and reviews diffs as they arrive.
4. **Merge:** a task merges only when it meets the Definition of Done and CI is green; its status becomes `[x]`. Merges happen in dependency order.
5. **Close:** the session ends with `main` green, the demo stack starting, statuses updated, and the next session's ready list printed. Record deviations in the task block and new decisions in an ADR.
6. **Re-plan:** after every few sessions, update `size_days` from actual durations and rerun the calculator; apply the cut list if the finish date moves past the deadline.

