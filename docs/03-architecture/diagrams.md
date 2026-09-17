# Diagram inventory

All diagrams are Mermaid in the documents listed; the report exports them as images. This page is the index and the home of diagrams that do not belong to one page.

| Diagram | Location |
|---|---|
| System context (C4-1) | [overview.md](overview.md) |
| Containers (C4-2), module dependencies (C4-3) | [overview.md](overview.md) |
| Deployment topologies | [deployment.md](deployment.md) |
| Control/application planes | [tenancy.md](tenancy.md) |
| Use-case diagram | [02-product/use-cases.md](../02-product/use-cases.md) |
| Sequence: onboarding, login/tenant resolution, ticket creation, SLA sweep | [02-product/user-flows.md](../02-product/user-flows.md) |
| Sequence: upload, realtime, webhook delivery | [storage.md](storage.md), [realtime.md](realtime.md), [07-api/webhooks.md](../07-api/webhooks.md) |
| State: ticket lifecycle | [04-domain/tickets.md](../04-domain/tickets.md) |
| State: SLA timer (orthogonal regions) | [05-algorithms/sla-evaluation.md](../05-algorithms/sla-evaluation.md) |
| ER diagram (overview / full) | [data.md](data.md), [08-database/entities.md](../08-database/entities.md) |
| Activity: assignment, duplicate detection | [05-algorithms/agent-assignment.md](../05-algorithms/agent-assignment.md), [duplicate-detection.md](../05-algorithms/duplicate-detection.md) |
| Data-flow diagrams (level 0/1) | below |
| Roadmap dependency graph | [roadmap/01-dependency-graph.md](../../roadmap/01-dependency-graph.md) |

## Data-flow diagram, level 0

```mermaid
flowchart LR
    Agent((Agent / Manager / Admin))
    Ext((External system))
    Contact((Contact))
    P0[Smart Helpdesk]
    Agent -->|ticket data, replies, settings| P0
    P0 -->|views, explanations, notifications| Agent
    Ext -->|API requests| P0
    P0 -->|responses, webhooks| Ext
    P0 -->|emails| Contact
```

## Data-flow diagram, level 1

```mermaid
flowchart LR
    A((Agent))
    E((External system))
    P1[1. Manage tickets]
    P2[2. Automate: score, assign, detect duplicates]
    P3[3. Monitor SLA]
    P4[4. Notify]
    P5[5. Integrate]
    P6[6. Analyse]
    D1[(Tickets)]
    D2[(Agents & Teams)]
    D3[(SLA timers)]
    D4[(Notifications)]
    D5[(Webhook log)]
    A --> P1 --> D1
    P1 --> P2
    P2 --> D1 & D2
    P1 --> P3 --> D3
    P3 --> P4 --> D4 --> A
    P1 & P3 --> P5 --> D5
    P5 <--> E
    D1 & D3 & D2 --> P6 --> A
```

## Activity: automatic re-evaluation pass (hourly)

```mermaid
flowchart TD
    S[Start hourly pass per tenant] --> L[Load open tickets in batches]
    L --> C{For each ticket}
    C --> P[PriorityStrategy.score with the new age]
    P --> R{level changed?}
    R -- yes --> U[Update level, explanation, history; SlaStrategy.recompute]
    R -- no --> N[Store score only]
    U --> C
    N --> C
    C -- done --> End
```

## Class diagram: domain model

```mermaid
classDiagram
    class Tenant {
        uuid id
        string slug
        string status
    }
    class User {
        uuid id
        string email
        string name
    }
    class AgentProfile {
        int capacity
        string availability
        datetime lastAssignedAt
    }
    class Team {
        string name
    }
    class Skill {
        string name
    }
    class Category {
        string name
    }
    class Organization {
        string name
        string tier
    }
    class Contact {
        string name
        string email
    }
    class Ticket {
        int number
        string title
        string status
        int impact
        int urgency
        float priorityScore
        string priorityLevel
    }
    class Comment {
        string visibility
        text body
    }
    class MediaItem {
        string name
        string mime
        int size
    }
    class SlaPolicy {
        string name
        float warningFraction
    }
    class SlaTimer {
        string kind
        string state
        datetime dueAt
        datetime warningAt
    }
    class BusinessCalendar {
        string timezone
        json weeklyHours
    }
    class EntityChange {
        string entityType
        int version
        json changes
    }
    class WebhookSubscription {
        string url
        string[] events
    }
    Tenant "1" --> "*" User
    Tenant "1" --> "*" Ticket
    User "1" --> "0..1" AgentProfile
    AgentProfile "*" --> "*" Team
    AgentProfile "*" --> "*" Skill
    Category "*" --> "*" Skill : requires
    Organization "1" --> "*" Contact
    Contact "1" --> "*" Ticket : requests
    Category "1" --> "*" Ticket
    AgentProfile "0..1" --> "*" Ticket : assigned
    Ticket "1" --> "*" Comment
    Ticket "*" --> "*" MediaItem : attachments
    Ticket "1" --> "2" SlaTimer
    SlaPolicy "1" --> "*" SlaTimer
    SlaPolicy "*" --> "0..1" BusinessCalendar
    Ticket "1" --> "*" EntityChange : history
    Tenant "1" --> "*" WebhookSubscription
```

## Class diagram: replaceable algorithm strategies

```mermaid
classDiagram
    class PriorityStrategy {
        <<interface>>
        +score(PriorityInput) PriorityResult
    }
    class AssignmentStrategy {
        <<interface>>
        +choose(TicketNeeds, AgentCandidate[]) AssignmentResult
    }
    class DuplicateStrategy {
        <<interface>>
        +find(TicketText, Candidate[]) DuplicateResult
    }
    class SlaStrategy {
        <<interface>>
        +start()
        +pause()
        +resume()
        +complete()
        +recompute()
        +check(now)
    }
    class BusinessCalendar {
        <<interface>>
        +add(start, duration)
        +elapsed(a, b)
    }
    class BasicWeightedPriority {
        weights
        thresholds
    }
    class LeastLoadedAgent
    class JaccardDuplicates {
        threshold
        WordSet words
    }
    class SimpleSlaTimer {
        warningFraction
    }
    class TwentyFourSevenCalendar
    class WorkingHoursCalendar {
        timezone
        weeklyHours
        holidays
    }
    class CreateTicket {
        +__invoke(data, actor) Ticket
    }
    PriorityStrategy <|.. BasicWeightedPriority
    AssignmentStrategy <|.. LeastLoadedAgent
    DuplicateStrategy <|.. JaccardDuplicates
    SlaStrategy <|.. SimpleSlaTimer
    BusinessCalendar <|.. TwentyFourSevenCalendar
    BusinessCalendar <|.. WorkingHoursCalendar
    SimpleSlaTimer --> BusinessCalendar
    CreateTicket --> PriorityStrategy
    CreateTicket --> AssignmentStrategy
    CreateTicket --> DuplicateStrategy
    CreateTicket --> SlaStrategy
```

## Object diagram: one seeded ticket

```mermaid
classDiagram
    class acme["acme : Tenant"] {
        slug = "acme"
    }
    class t1042["t1042 : Ticket"] {
        number = 1042
        impact = 2
        urgency = 3
        priorityLevel = "P3"
        status = "assigned"
    }
    class ravi["ravi : Contact"] {
        email = "ravi@globex.test"
    }
    class globex["globex : Organization"] {
        tier = "premium"
    }
    class billing["billing : Category"] {
        name = "Billing"
    }
    class chen["chen : AgentProfile"] {
        capacity = 10
        openTickets = 4
    }
    class res["resolution : SlaTimer"] {
        state = "running"
        dueAt = "Fri 16:00"
    }
    class office["office : BusinessCalendar"] {
        timezone = "Asia/Kathmandu"
    }
    acme --> t1042
    ravi --> t1042
    globex --> ravi
    billing --> t1042
    chen --> t1042
    t1042 --> res
    res --> office
```
