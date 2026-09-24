::: center
![](figures/tu-logo.png =80)

**Tribhuvan University**

**Faculty of Humanities and Social Sciences**

**{{college}}**
:::

<!-- same-page -->
# Supervisor's Recommendation

I hereby recommend that this project prepared under my supervision by {{authors_upper}} entitled "{{short_title}}: A Multi-Tenant Support Ticket Management System with Automated Prioritization, Agent Assignment, Duplicate Detection, SLA Monitoring, Integrations, and Analytics" in partial fulfillment of the requirements for the degree of Bachelor in Computer Application is recommended for the final evaluation.

::: center
.............................................

**{{supervisor}}**

Supervisor

{{department}}

{{college}}, {{college_address}}
:::

<!-- pagebreak -->

::: center
![](figures/tu-logo.png =80)

**Tribhuvan University**

**Faculty of Humanities and Social Sciences**

**{{college}}**
:::

<!-- same-page -->
# Letter of Approval

This is to certify that this project prepared by {{authors_upper}} entitled "{{short_title}}: A Multi-Tenant Support Ticket Management System with Automated Prioritization, Agent Assignment, Duplicate Detection, SLA Monitoring, Integrations, and Analytics" in partial fulfillment of the requirements for the degree of Bachelor in Computer Application has been evaluated. In our opinion it is satisfactory in the scope and quality as a project for the required degree.

<!-- table: plain -->
| <br><br>.............................................<br>**SUPERVISOR**<br>{{supervisor}}<br>{{department}}<br>{{college}} | <br><br>.............................................<br>**HOD / CO-ORDINATOR**<br>{{hod}}<br>{{department}}<br>{{college}} |
|---|---|
| <br><br>.............................................<br>**INTERNAL EXAMINER**<br>Name:<br>Date: | <br><br>.............................................<br>**EXTERNAL EXAMINER**<br>Name:<br>Date: |

# Acknowledgement

We are glad to present this report on "{{short_title}}", prepared as our CACS452 Project III in the eighth semester of the Bachelor in Computer Application. We express our sincere gratitude to everyone who guided and supported us throughout the project.

We extend our genuine thanks to our project supervisor, {{supervisor}}, for his continuous guidance, constructive feedback and encouragement from the proposal to the final defence. We are equally thankful to {{hod}}, Head of the {{department}}, for his support and for the academic environment in which this work was possible.

We are indebted to the {{department}}, {{college}}, and to the faculty members whose courses in database management, software engineering, web technology, operating systems, data mining and research methodology laid the foundation of this project. We also thank our families and friends for their patience and support, and the open-source communities whose frameworks, databases and tools allowed us to focus our own work on the system's design, its algorithms and its verification.

::: center
{{author}} ({{roll}})

{{author2}} ({{roll2}})
:::

# Abstract

Small and medium support teams often handle customer and internal requests through shared mailboxes or basic ticketing tools in which priority is set by hand, work is assigned by habit, one outage produces many unrelated tickets, and service-level breaches are noticed only after they happen. This project designs, implements and evaluates **{{short_title}}**, a multi-tenant support ticket management system that one installation offers to many independent organisations, as a hosted service or on an organisation's own server. It is built as a modular monolith with a Laravel application programming interface, a React single-page application, PostgreSQL, Valkey and S3-compatible object storage, packaged with Docker Compose and deployed to a cloud virtual machine.

Four operational decisions are made by the system's own, deliberately simple and replaceable algorithms, and every decision is explained to the agent: a weighted priority score over impact, urgency, customer tier and waiting time; assignment to the eligible agent with the lowest load relative to capacity, ties going to the agent assigned longest ago; duplicate detection by the Jaccard similarity of normalised word sets; and a service-level agreement (SLA) timer state machine with pause, recomputation and working-hours calendars. A reporting module captures every change to the main records with a database trigger, reconstructs any record as it was at a past moment, and analyses backlog, workload and time in status. Tenant isolation is enforced by scoped data access and PostgreSQL row-level security and verified by an automated isolation suite. The platform also provides a media library, email notifications and email-to-ticket through a bundled mail server, a documented REST API with OAuth 2.0 client credentials, signed webhooks, a management dashboard and 28 catalogue reports.

The algorithms were evaluated on reproducible datasets. Least-loaded assignment reached a Jain's fairness index of 0.967 on 500 tickets, against 0.865 for round robin and 0.786 for random assignment, with no capacity overflow. Duplicate detection reached precision 0.95, recall 0.70 and F1 0.81 on held-out labelled pairs at the default threshold. All 12 priority scenarios and all 10 SLA timelines produced the expected results, and history reconstruction was correct for all 1 000 sampled records. The system passed 1 971 backend tests, 279 frontend unit tests, 319 browser component tests and 41 end-to-end tests.

**Keywords:** helpdesk, multi-tenancy, ticket prioritisation, load balancing, duplicate detection, Jaccard similarity, service level agreement, change data capture
