# History reconstruction and time analytics

Module: `App\Modules\Reporting\Domain\History\ChangeReplayer`, `…\Domain\Intervals\{IntervalBuilder, IntervalMeasures}`, `…\Domain\Stats\Percentile` (built in M1-22), `SnapshotBuilder` (M2), `FairnessIndex` (reused from Automation), plus the PL/pgSQL trigger `record_entity_change()`. Decision: [ADR-0022](../adr/0022-reporting-and-history.md). Reports: [04-domain/reporting.md](../04-domain/reporting.md).

## 1. Background

- **Temporal data.** Answering "what did this record look like on 3 September?" requires either keeping every version (transaction-time history, as in SQL:2011 system-versioned tables) or keeping every change and replaying it. PostgreSQL has no built-in system versioning, so the system records changes with a trigger and reconstructs versions on demand.
- **Change data capture.** Recording row changes at the database level captures every write path (application models, bulk updates, maintenance scripts), which application-level hooks cannot guarantee.
- **Interval analysis.** Durations such as "time in status" and point-in-time counts such as "backlog at 17:00" are computed from ordered state-change events by turning them into non-overlapping intervals (a sweep over events sorted by time).
- **Business time** uses the same `BusinessCalendar` interface as the SLA engine ([sla-evaluation.md](sla-evaluation.md)).
- **Fairness** over time reuses Jain's index ([agent-assignment.md](agent-assignment.md)).

## 2. Change capture

```sql
CREATE OR REPLACE FUNCTION record_entity_change() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  old_j jsonb := CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END;
  new_j jsonb := CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END;
  row_j jsonb := coalesce(new_j, old_j);
  excluded text[] := TG_ARGV;                -- columns never recorded (secrets, hashes, bodies)
  diff jsonb := '{}'::jsonb;
  entity uuid := (row_j ->> 'id')::uuid;
  tenant uuid;
  k text;
BEGIN
  FOR k IN SELECT jsonb_object_keys(row_j) LOOP
    CONTINUE WHEN k = ANY (excluded) OR k = 'updated_at';
    CONTINUE WHEN TG_OP = 'UPDATE' AND (old_j -> k) IS NOT DISTINCT FROM (new_j -> k);
    diff := diff || jsonb_build_object(k, jsonb_build_object('old', old_j -> k, 'new', new_j -> k));
  END LOOP;
  IF TG_OP = 'UPDATE' AND diff = '{}'::jsonb THEN RETURN NULL; END IF;     -- nothing reportable changed

  tenant := coalesce((row_j ->> 'tenant_id')::uuid, nullif(current_setting('app.current_tenant', true), '')::uuid);
  IF tenant IS NULL THEN
    RAISE EXCEPTION 'record_entity_change(): no tenant for %(%)', TG_TABLE_NAME, entity USING ERRCODE = '23502';
  END IF;
  IF TG_OP = 'DELETE' AND NOT EXISTS (SELECT 1 FROM tenants WHERE id = tenant) THEN RETURN NULL; END IF;

  INSERT INTO entity_changes (id, tenant_id, entity_type, entity_id, version, operation, changes,
                              actor_type, actor_id, request_id, occurred_at)
  VALUES (uuidv7(), tenant, TG_TABLE_NAME, entity,
          coalesce((SELECT max(version) FROM entity_changes
                    WHERE entity_type = TG_TABLE_NAME AND entity_id = entity), 0) + 1,
          lower(TG_OP), diff,
          coalesce(nullif(current_setting('app.actor_type', true), ''), 'system'),
          nullif(current_setting('app.actor_id', true), '')::uuid,
          nullif(current_setting('app.request_id', true), ''), clock_timestamp());
  RETURN NULL;
END $$;

CREATE TRIGGER tickets_changes AFTER INSERT OR UPDATE OR DELETE ON tickets
  FOR EACH ROW EXECUTE FUNCTION record_entity_change('search_vector');
```

Properties: one row per effective change; versions are consecutive per entity (the `(entity_type, entity_id, version)` unique index turns a concurrent race into a retry); the actor is whatever the application set for the transaction (`system` for jobs and the scheduler, `api_client`, `user`, `email`). The trigger function is covered by database tests.

**Implementation (M1-23).** The function above is the as-built one
(`app/Modules/Reporting/Database/Migrations/…_create_entity_changes_table.php`). A migration attaches
it with `App\Modules\Reporting\Support\ReportableTables::captureChanges($table)`, which creates the
trigger `<table>_changes` and passes the table's excluded columns as trigger arguments. That class is
the registry of reportable tables (`TABLES`: table => excluded columns); the schema test
`tests/Feature/Reporting/ChangeCaptureSchemaTest.php` compares it with `pg_trigger`, so a later
migration that creates a reportable table and forgets the trigger — or attaches one without
registering the table — fails. Decisions:

- **Session settings.** `RlsTenancyBootstrapper` sets `app.current_tenant`, `app.request_id`,
  `app.actor_type` and `app.actor_id` with `set_config(…, false)` when tenancy is initialised and
  `RESET`s all four when it ends. The actor is the user a guard has already resolved (`user` plus its
  id); jobs, console commands and the scheduler therefore record `system`, and so does any write with
  no setting at all (`coalesce(…, 'system')`), for example a maintenance script. Authentication runs
  *after* tenancy in the `tenant` middleware group, so the Reporting provider listens for
  `Authenticated` and calls `RlsTenancyBootstrapper::refreshActor()`.
  MVP-SHORTCUT: a bearer-token request resolves through a guard that fires no `Authenticated` event
  and is recorded as `system`; M3-04 gives API clients the `api_client` actor type.
- **Tenant.** The row's own `tenant_id` wins over `app.current_tenant` (it is NOT NULL and immutable,
  so it is the truth even for a bulk write made in another session context); the setting is the
  fallback for a reportable table without a `tenant_id` column. Neither: the write is rejected
  (`23502`), so `entity_changes.tenant_id` is never guessed.
- **Excluded columns.** `updated_at` for every table, plus the registered per-table list
  (`users`: `password`, `remember_token`). An update of excluded columns only writes no row.
- **Tenant deletion.** `entity_changes.tenant_id` references `tenants` with `ON DELETE CASCADE`, so a
  delete that happens because the tenant itself is being deleted is not recorded; its history is
  going away in the same statement.
- **Precision and order.** `occurred_at` is `timestamptz(6)` from `clock_timestamp()` (the real clock,
  so changes inside one transaction keep their order); `version` comes from `max(version) + 1` per
  `(entity_type, entity_id)` and the unique index turns a concurrent race into a retry.
- **Append-only.** The runtime role keeps `INSERT` (through the trigger) and `SELECT`; `UPDATE`,
  `DELETE` and `TRUNCATE` are revoked. Reads go through `App\Modules\Reporting\Models\EntityChange`
  (tenant-scoped, `changedAttributes()` because Eloquent already has a `$changes` property) and
  `EntityChange::toRecordedChange()` hands the row to the replayer of §3.
- **Subjects so far.** `users` and `tenant_settings`; tickets, contacts, organisations and the rest of
  the ADR-0022 list are registered by their own migrations in M2.

## 3. Point-in-time reconstruction (backward replay)

Given the current row `S_now` and the ordered changes `c_1 … c_n` (versions ascending), the state at time `t` is obtained by undoing, newest first, every change that happened after `t`:

```text
function asOf(entity, t):
    changes ← entity_changes where entity = entity and occurred_at > t order by version desc
    if entity was created after t: return NOT_EXISTING
    state ← current row (or, if deleted, {} )
    for c in changes:
        if c.operation = 'insert': return NOT_EXISTING          // created after t
        for (attribute, {old, new}) in c.changes:
            state[attribute] ← old                               // undo
    return state
```

Deleted entities are reconstructed from the delete change, whose `old` values hold the last row. Complexity: O(k · a) for k changes after `t` and a changed attributes each; one indexed range query. A forward replay (apply `new` values from the insert onwards) gives the same result and is used by the verification test.

**Correctness property (tested):** for a random sequence of updates applied at recorded instants `t_1 < … < t_m`, with the full row saved after each step, `asOf(entity, t_i)` equals the saved row for every `i`, and forward and backward replay agree.

**Implementation (M1-22).** `ChangeReplayer::asOf(?array $current, list<EntityChange> $changes, $t)` is the backward replay and `forwardTo($changes, $t, ?array $initial = null)` the forward one; both return an attribute map or `null` (did not exist). Decisions:

- A change belongs to the state at `t` when `occurred_at ≤ t` (a change made exactly at `t` is already applied); several changes at one instant are all applied.
- Changes are ordered by `version`; changes may be passed in any order. A duplicate version, or a version whose `occurred_at` is earlier than the previous version's, raises `InvalidHistory`, so both replays cut the history at the same place.
- Forward replay starts from the insert (or from `$initial` when capture started after creation); an insert on an existing state, or an update/delete on a missing one, raises `InvalidHistory`.
- Attributes the trigger never records (excluded columns, `updated_at`) keep their current value in backward replay and are absent from forward replay, so the two agree only on recorded attributes.
- `EntityChange::fromStored()` builds a change from the stored text operation and decoded `changes` jsonb (a missing `old`/`new` is null).
- The property test runs 500 seeded (Mt19937) random histories of up to 25 effective updates, with repeated instants and a delete in about a quarter of them, and checks at every recorded instant (and at −1 s, +1 s and +450 s from it) that backward replay equals the saved row and forward replay.

### Worked example (as of)

A ticket is created on 1 September 09:00 (`open`, P3, unassigned), assigned to Asha at 10:00 (v2), raised to P2 on 2 September 09:00 (v3) and resolved on 3 September 12:00 (v4). Current row: `resolved`, P2, Asha.

| As of | Undone versions | State |
|---|---|---|
| 31 Aug 23:59 | v4, v3, v2, v1 (insert) | did not exist |
| 1 Sep 09:30 | v4, v3, v2 | `open`, P3, unassigned |
| 1 Sep 10:00 | v4, v3 | `assigned`, P3, Asha (v2 happened exactly then) |
| 2 Sep 08:00 | v4, v3 | `assigned`, P3, Asha |
| 4 Sep 00:00 | none | current row |

If the ticket is then deleted (v5, 4 September 08:00), the current row is gone and the state as of 3 September 13:00 comes back from v5's `old` values.

## 4. Status intervals (sweep)

For each ticket, the ordered domain events (created, status changed, assigned/unassigned, priority changed, team changed, closed as duplicate) are swept to produce maximal intervals of constant `(status, assignee, team, priority)`:

```text
function intervals(ticket, events, calendar, now):
    state ← initial state from the "created" event
    start ← ticket.created_at
    result ← []
    for e in events ordered by (occurred_at, id):
        next ← apply(state, e)
        if next ≠ state:
            result.append(interval(state, start, e.occurred_at, cal.elapsed(start, e.occurred_at)))
            state, start ← next, e.occurred_at
    result.append(interval(state, start, null, cal.elapsed(start, now)))   // open interval
    return result
```

The last interval is open (`ends_at = null`) until the next change. Intervals are rebuilt for the ticket on every domain event (the ticket's events are few), which makes the job idempotent. Complexity O(e) per ticket.

**Implementation (M1-22).** `IntervalBuilder(Clock)->build(array $initial, $createdAt, list<StateEvent> $events, BusinessCalendar $calendar, ?$now = null)` returns `StateInterval`s (`sequence`, `state`, `startsAt`, `endsAt`, `seconds`, `businessSeconds`). Decisions:

- The tracked attributes are the keys of the initial state (for tickets `IntervalBuilder::TICKET_ATTRIBUTES` = status, assignee_id, team_id, priority), so the same builder sweeps any attribute set (for example agent availability). A `StateEvent` carries only the values it sets; values of untracked attributes are ignored, and an event that changes nothing tracked does not split an interval.
- Events are ordered by `(occurred_at, id)`; an event before the creation instant raises `InvalidHistory`.
- Two changes at the same instant give a zero-length interval, which is kept so that every transition (and every reassignment) is visible.
- The last interval is open (`endsAt = null`); its durations run to `$now`, which defaults to `Clock::now()`. A `now` before its start gives zero durations.
- Wall-clock seconds are the difference of Unix timestamps (so daylight-saving changes count their true length); business seconds come from `BusinessCalendar::elapsed()`.
- `IntervalMeasures` gives the per-ticket reference measures: `timeBy($intervals, $attribute)` (time in status; null values under the key `''`), `at($intervals, $t)` (the interval with `starts_at ≤ t < coalesce(ends_at, ∞)`) and `switches($intervals, 'assignee_id')` (reassignments: agent to a different agent; assigning from or unassigning to nobody does not count). Backlog and load across tickets stay SQL (M2-13); unassigned time is computed in the facts job (M2-13).

### Worked example (intervals)

Office hours Sunday–Friday 10:00–17:00, Asia/Kathmandu (the SLA example calendar). A ticket is created on Thursday 17 September 2026 at 15:00 (`open`, unassigned, team Support, P3); assigned to Asha at 15:30; `in_progress` at 16:00; `pending` on Friday at 16:30; `in_progress` on Sunday at 11:00; reassigned to Chen at 12:00; `resolved` at 14:00. Now is Monday 10:00.

| # | Status | Assignee | From | To | Wall-clock | Business |
|---|---|---|---|---|---|---|
| 0 | open | – | Thu 15:00 | Thu 15:30 | 0.5 h | 0.5 h |
| 1 | assigned | Asha | Thu 15:30 | Thu 16:00 | 0.5 h | 0.5 h |
| 2 | in_progress | Asha | Thu 16:00 | Fri 16:30 | 24.5 h | 7.5 h |
| 3 | pending | Asha | Fri 16:30 | Sun 11:00 | 42.5 h | 1.5 h (Saturday closed) |
| 4 | in_progress | Asha | Sun 11:00 | Sun 12:00 | 1 h | 1 h |
| 5 | in_progress | Chen | Sun 12:00 | Sun 14:00 | 2 h | 2 h |
| 6 | resolved | Chen | Sun 14:00 | open | 20 h | 3 h |

Time in `in_progress` = 27.5 h wall-clock, 10.5 h business; reassignments = 1; on Saturday at 12:00 the ticket was `pending` (in the backlog).

Derived measures:

- **Time in status s** = Σ duration of intervals with status s.
- **Backlog at instant t** = count of intervals with `starts_at ≤ t < coalesce(ends_at, ∞)` and status not in {resolved, closed}; served by a GiST index on `tstzrange(starts_at, ends_at)` or a B-tree on `(tenant_id, starts_at)` with a filter.
- **Agent load at t** = Σ priority weight of such intervals grouped by assignee (same definition as assignment, [agent-assignment.md](agent-assignment.md)).
- **Unassigned time** = Σ duration of intervals with no assignee before resolution.
- **Reassignments** = number of interval boundaries where the assignee changed from one agent to another.

## 5. Daily snapshots and backfill

```text
function snapshotDay(tenant, day):
    t_end ← end of day in the tenant calendar's time zone
    for each dimension set D in {none, team, agent, priority, category, status}:
        rows ← backlog and load at t_end grouped by D                 // from intervals
             + flows during the day grouped by D (created, resolved, reopened, breached)
        upsert report_daily_snapshots (tenant, day, D, key, metrics)
```

`reports:rebuild` recomputes intervals and facts for all tickets of a tenant, then snapshots from the first ticket's day to yesterday; it is idempotent because all writes are upserts keyed by natural keys. `reports:verify` samples tickets and days and compares stored values with a fresh computation.

## 6. Percentiles and rates

Medians and 90th percentiles use PostgreSQL `percentile_cont` over facts; rates are reported with their denominators so that small samples are visible; comparisons show absolute and relative change and are suppressed when the previous period has fewer than five records.

**Implementation (M1-22).** `Percentile::continuous($values, $fraction)` uses the same method as `percentile_cont` (linear interpolation: position `f · (n − 1)` on the sorted values), so PHP reference values match the SQL reports; `median()` and `p90()` are shortcuts and `mean()` is the arithmetic mean. Empty input gives `null`; a fraction outside 0..1 or a non-finite value raises `InvalidArgumentException`. Worked example: first-response hours 10, 1, 4, 2, 3 give median 3, p90 7.6 (position 3.6: 4 + 0.6 × 6) and mean 4; 4, 1, 3, 2 give median 2.5 and p90 3.7.

## 7. Complexity summary

| Operation | Cost |
|---|---|
| Change capture | O(columns) per written row |
| As-of reconstruction | O(changes after t) |
| Interval rebuild | O(events of the ticket) per event |
| Backlog at t | one indexed range scan |
| Daily snapshot | O(open intervals + day's events) per tenant |

## 8. Tests

Trigger tests (insert/update/delete, excluded columns, no-op updates ignored, actor settings); replay property test; interval builder unit tests (every transition, reopen, duplicate close, unassign, priority change, open interval); business-time durations with calendars; backlog-at-instant against a brute-force count on generated data; snapshot idempotency; rebuild equals incremental result; isolation (history and reports never show another tenant's rows).

## 9. Experiment (E6)

On the demo tenant plus a generated history of 10 000 tickets: (a) reconstruction correctness rate over 1 000 random (entity, instant) samples, expected 100 %; (b) agreement between incremental and rebuilt read models, expected 100 %; (c) latency of the heaviest reports (backlog over 90 days, time in status by team, contact 360) at the 95th percentile; (d) write overhead of the trigger measured as ticket-update latency with and without capture.

## 10. Limitations

History starts when capture is installed (no history for imported data unless the import writes it); attribute-level history only for recorded tables; text bodies are not versioned; monthly partitioning is V1.
