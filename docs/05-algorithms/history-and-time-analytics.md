# History reconstruction and time analytics

Module: `App\Modules\Reporting\Domain\{ChangeReplayer, IntervalBuilder, SnapshotBuilder, Percentile, FairnessIndex}` plus the PL/pgSQL trigger `record_entity_change()`. Decision: [ADR-0022](../adr/0022-reporting-and-history.md). Reports: [04-domain/reporting.md](../04-domain/reporting.md).

## 1. Background

- **Temporal data.** Answering "what did this record look like on 3 September?" requires either keeping every version (transaction-time history, as in SQL:2011 system-versioned tables) or keeping every change and replaying it. PostgreSQL has no built-in system versioning, so the system records changes with a trigger and reconstructs versions on demand.
- **Change data capture.** Recording row changes at the database level captures every write path (application models, bulk updates, maintenance scripts), which application-level hooks cannot guarantee.
- **Interval analysis.** Durations such as "time in status" and point-in-time counts such as "backlog at 17:00" are computed from ordered state-change events by turning them into non-overlapping intervals (a sweep over events sorted by time).
- **Business time** uses the same `BusinessCalendar` interface as the SLA engine ([sla-evaluation.md](sla-evaluation.md)).
- **Fairness** over time reuses Jain's index ([agent-assignment.md](agent-assignment.md)).

## 2. Change capture

```sql
CREATE FUNCTION record_entity_change() RETURNS trigger AS $$
DECLARE
  old_j jsonb := CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END;
  new_j jsonb := CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END;
  excluded text[] := TG_ARGV;                -- columns never recorded (secrets, hashes, bodies)
  diff jsonb := '{}';
  k text;
BEGIN
  FOR k IN SELECT jsonb_object_keys(coalesce(new_j, old_j)) LOOP
    CONTINUE WHEN k = ANY(excluded) OR k IN ('updated_at');
    IF TG_OP = 'UPDATE' AND (old_j -> k) IS NOT DISTINCT FROM (new_j -> k) THEN CONTINUE; END IF;
    diff := diff || jsonb_build_object(k, jsonb_build_object('old', old_j -> k, 'new', new_j -> k));
  END LOOP;
  IF TG_OP = 'UPDATE' AND diff = '{}' THEN RETURN NULL; END IF;
  INSERT INTO entity_changes (id, tenant_id, entity_type, entity_id, version, operation, changes,
                              actor_type, actor_id, request_id, occurred_at)
  VALUES (uuidv7(), (coalesce(new_j, old_j) ->> 'tenant_id')::uuid, TG_TABLE_NAME, (coalesce(new_j, old_j) ->> 'id')::uuid,
          coalesce((SELECT max(version) FROM entity_changes
                    WHERE entity_type = TG_TABLE_NAME AND entity_id = (coalesce(new_j, old_j) ->> 'id')::uuid), 0) + 1,
          lower(TG_OP), diff,
          current_setting('app.actor_type', true), nullif(current_setting('app.actor_id', true), '')::uuid,
          current_setting('app.request_id', true), clock_timestamp());
  RETURN NULL;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER tickets_changes AFTER INSERT OR UPDATE OR DELETE ON tickets
  FOR EACH ROW EXECUTE FUNCTION record_entity_change('search_vector');
```

Properties: one row per effective change; versions are consecutive per entity (the `(entity_type, entity_id, version)` unique index turns a concurrent race into a retry); the actor is whatever the application set for the transaction (`system` for jobs and the scheduler, `api_client`, `user`, `email`). The trigger function is covered by database tests.

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
