# Agent assignment — Least-Loaded Eligible Agent (academic baseline)

Contract: `AssignmentStrategy`. Baseline class: `App\Modules\Automation\Strategies\Baseline\LeastLoadedAgent`. Replaceable after the defence ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)); richer candidate: [future/agent-assignment-advanced.md](future/agent-assignment-advanced.md). Requirements FR-AUT-04..05. Data model: [agents-and-teams.md](../04-domain/agents-and-teams.md).

## Idea

Send each new ticket to the **qualified agent who is least busy relative to their capacity**. This is the "least connections" rule of network load balancers [NGINX] combined with the skill filter used in contact centres [Gans et al.]. When two agents are equally busy, the one who received a ticket longest ago wins, which is round robin [NGINX].

## Step 1 — who can take the ticket?

An agent is **eligible** when all of these hold:

1. the agent is active and marked *available* (and, if the tenant enforces shifts, is on shift now);
2. the agent has every skill the ticket's category requires (no requirement → any agent);
3. if the ticket already has a team, the agent is in that team;
4. the agent's open tickets are fewer than their capacity.

"Open tickets" means tickets assigned to the agent in status assigned, in progress or pending.

## Step 2 — pick one

```text
load(agent) = open_tickets(agent) ÷ capacity(agent)

choose the eligible agent with the smallest load;
if equal: the one whose last assignment is oldest (never assigned counts as oldest);
if still equal: the smallest agent id
```

## Flow

```mermaid
flowchart TD
    A[New ticket or auto-assign request] --> B[Load agents of the tenant with skills, teams, status and open tickets]
    B --> C{For each agent: available, has skills, in team, below capacity?}
    C -- no --> X[Record exclusion reason]
    C -- yes --> E[Add to eligible list with load = open / capacity]
    X --> F{More agents?}
    E --> F
    F -- yes --> C
    F -- no --> G{Eligible list empty?}
    G -- yes --> H[Leave unassigned and notify managers]
    G -- no --> I[Sort by load, then oldest last assignment, then id]
    I --> J[Assign first agent and store the list as explanation]
```

## Algorithm

```text
function choose(ticket, agents):
    eligible ← []
    for a in agents:
        reason ← whyNotEligible(a, ticket)       // null when eligible
        if reason = null: eligible.append(a)
        else: explanation.excluded(a, reason)
    if eligible is empty:
        return NO_AGENT with explanation          // ticket stays unassigned; managers are notified
    sort eligible by (load(a), lastAssignedAt(a), a.id)
    return eligible[0] with explanation.ranking(eligible)
```

The chosen agent's open-ticket count and last-assignment time are updated in the same database transaction, with the ticket row locked so two requests cannot assign it twice.

**Complexity:** O(A log A) for A agents in the tenant (the sort); A is small.

## Worked example

Ticket in category *Billing* (requires skill `billing`), no team yet.

| Agent | Skill billing | Status | Open / capacity | Load | Last assigned | Result |
|---|---|---|---|---|---|---|
| Asha | yes | available | 3 / 10 | 0.30 | 09:40 | eligible |
| Bikram | yes | available | 2 / 5 | 0.40 | 08:15 | eligible |
| Chen | yes | available | 3 / 10 | 0.30 | 09:10 | eligible |
| Dev | no | available | 0 / 8 | — | — | excluded: missing skill `billing` |
| Esha | yes | away | 1 / 10 | — | — | excluded: not available |

Asha and Chen share the lowest load (0.30); Chen was assigned earlier, so **Chen** gets the ticket. Bikram has fewer tickets than both but a smaller capacity, so his load is higher.

## Tests

Each exclusion reason; lowest load wins; tie goes to the oldest last assignment; never-assigned agent wins a tie; identical agents rotate like round robin over repeated assignments; no eligible agent returns no assignment with reasons; shuffled input gives the same result (contract test); concurrent assignment of one ticket succeeds once.

## Evaluation (experiment E1)

Replay 500 generated tickets over 8 agents with different skills and capacities under three policies: **random**, **round robin** (skills respected) and this **baseline**. Measure the spread of load (standard deviation, maximum, minimum), Jain's fairness index [Jain et al.] and the number of capacity overflows.

## Limitations and replacement

Skill strength, customer history and ticket priority are ignored; each ticket is decided alone. Replacement options: weighted scoring with skill levels and affinity, batch optimisation with the Hungarian method or OR-Tools, or learned routing ([future](future/README.md)).
