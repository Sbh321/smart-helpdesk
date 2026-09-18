# Contributing

Smart Helpdesk is built by one developer steering coding agents on parallel tracks. These rules apply to every change, whoever types it.

## Before you start

1. Read `CLAUDE.md` (reading order and non-negotiables).
2. Run `just plan` and pick a ready task from `roadmap/`; mark it `[~]` in its milestone file.
3. Read the documentation pages the task links to.

## While working

- Follow the conventions in `docs/03-architecture/backend.md` and `docs/03-architecture/frontend.md`.
- Every tenant-owned table has `tenant_id`, the tenant traits, RLS and (if reportable) the change-capture trigger.
- Check permissions (`can:tickets.assign`), never roles.
- Algorithms are used through their strategy contracts (ADR-0023).
- No new dependency without a row in `docs/01-research/` explaining why.
- Mark deliberate simplifications with `// MVP-SHORTCUT: <reason>; V1: <backlog item>`.

## Finishing a task

A task is done only when it meets `docs/10-quality/definition-of-done.md`:

- acceptance criteria met and tests written at the levels the task names;
- `just lint` and `just test` pass;
- the documentation pages named in the task are updated in the same change;
- the task is marked `[x]` in its milestone file, with any deviation noted in the task block.

## Commits and branches

- `main` is always green. Work on short-lived branches named `m1-04-compose-stack` and merge after review.
- Commit messages follow Conventional Commits: `type(scope): summary` (`feat`, `fix`, `docs`, `test`, `refactor`, `build`, `ci`, `chore`). The `commit-msg` hook checks the subject.
- Never commit `.env` files or anything under `secrets/`.

## Useful commands

```sh
mise install        # host tools (Node, pnpm, just, lefthook, OpenTofu, Ansible)
lefthook install    # git hooks
just setup          # first run
just up / just down
just test / just lint / just types
just plan           # ready tasks and dates
just report         # rebuild the university and internship reports
```
