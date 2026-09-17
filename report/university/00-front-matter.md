# Supervisor's Recommendation

I hereby recommend that this project, prepared under my supervision by **{{author}}** ({{roll}}), entitled "**{{title}}**", in partial fulfillment of the requirements for the degree of Bachelor in Computer Application, is recommended for final evaluation.

::: center
.............................................

**{{supervisor}}**

{{supervisor_designation}}

Department of Computer Application

{{college}}
:::

# Letter of Approval

This is to certify that this project prepared by **{{author}}** ({{roll}}), entitled "**{{title}}**", in partial fulfillment of the requirements for the degree of Bachelor in Computer Application, has been evaluated. In our opinion it is satisfactory in scope and quality as a project for the required degree.

| Role | Name and signature |
|---|---|
| Supervisor | {{supervisor}} |
| Head of Department / Program Coordinator | {{hod}} |
| Internal Examiner | {{internal_examiner}} |
| External Examiner | {{external_examiner}} |

# Acknowledgement

I would like to express sincere gratitude to my supervisor, {{supervisor}}, for continuous guidance, constructive feedback and encouragement throughout this project. I am thankful to {{hod}} and the Department of Computer Application, {{college}}, for providing the academic environment and resources required to complete this work, and to the faculty members whose courses in database systems, software engineering, web technology, operating systems and data mining laid the foundation for it.

I also thank my family and friends for their support, and the open-source communities whose frameworks, databases and tools made it possible to build a complete system and to focus the project's own contribution on its algorithms and architecture.

::: center
{{author}}
:::

# Abstract

Small and medium support teams often manage customer and internal requests with shared mailboxes or basic ticketing tools in which priority is set by hand, work is assigned by habit, the same outage produces many unrelated tickets, and service-level breaches are noticed only after they happen. This project designs and implements **Smart Helpdesk**, a multi-tenant support ticket management system that can be offered as a hosted service or installed on an organisation's own server. The system is built as a modular monolith with a Laravel application programming interface, a React single-page application, PostgreSQL, Valkey and S3-compatible object storage, packaged with Docker Compose and deployable to any virtual machine.

Four decision modules were implemented by the author as simple, explainable and replaceable algorithms: a weighted priority score over impact, urgency, customer tier and waiting time; a least-loaded assignment rule over agents with the required skills and free capacity, with round-robin tie-breaking; a duplicate detector based on the Jaccard similarity of word sets; and an SLA timer state machine with pause, recomputation and working-hours calendars. A reporting module records every change to the main entities through a database trigger, reconstructs any record as it existed at a past moment, and analyses backlog, workload and time in status over time. Tenant isolation is enforced by scoped data access, PostgreSQL row-level security and an automated isolation test suite. The platform also provides a media library, email notification and email-to-ticket processing through a bundled mail server, a versioned REST API with OAuth2 client credentials, signed webhooks and a management dashboard.

The algorithms were evaluated with reproducible datasets. Assignment fairness reached a Jain's index of <<pending>> compared with <<pending>> for round robin; duplicate detection achieved precision <<pending>>, recall <<pending>> and F1 <<pending>> at the selected threshold; all <<pending>> SLA scenarios produced the expected timer states; history reconstruction was correct in <<pending>> of sampled cases.

**Keywords:** helpdesk, multi-tenancy, ticket prioritisation, load balancing, duplicate detection, service level agreement, information retrieval
