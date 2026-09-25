::: center
![](figures/tu-logo.png =80)

**Tribhuvan University**

**Faculty of Humanities and Social Sciences**

**{{college}}**
:::

<!-- same-page -->
# Supervisor's Recommendation

I hereby recommend that this project prepared under my supervision by {{authors_upper}} entitled "{{short_title}}: A Multi-Tenant Support Ticket Management System" in partial fulfillment of the requirements for the degree of Bachelor in Computer Application is recommended for the final evaluation.

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

This is to certify that this project prepared by {{authors_upper}} entitled "{{short_title}}: A Multi-Tenant Support Ticket Management System" in partial fulfillment of the requirements for the degree of Bachelor in Computer Application has been evaluated. In our opinion it is satisfactory in the scope and quality as a project for the required degree.

<!-- table: plain -->
| <br><br>.............................................<br>**SUPERVISOR**<br>{{supervisor}}<br>{{department}}<br>{{college}} | <br><br>.............................................<br>**HOD / CO-ORDINATOR**<br>{{hod}}<br>{{department}}<br>{{college}} |
|---|---|
| <br><br>.............................................<br>**INTERNAL EXAMINER** | <br><br>.............................................<br>**EXTERNAL EXAMINER** |

# Acknowledgement

We are glad to present this report on "{{short_title}}", prepared as our CACS452 Project III in the eighth semester of the Bachelor in Computer Application. We express our sincere gratitude to everyone who guided and supported us throughout the project.

We extend our genuine thanks to our project supervisor, {{supervisor}}, for his continuous guidance, constructive feedback and encouragement from the proposal to the final defence. We are equally thankful to {{hod}}, Head of the {{department}}, for his support and for the academic environment in which this work was possible.

We are indebted to the {{department}}, {{college}}, and to the faculty members whose courses in database management, software engineering, web technology, operating systems, data mining and research methodology laid the foundation of this project. We also thank our families and friends for their patience and support, and the open-source communities whose frameworks, databases and tools allowed us to focus our own work on the system's design, its algorithms and its verification.


# Abstract

Small support teams often handle requests through shared mailboxes or basic ticketing tools in which priority is set by hand, work is assigned by habit, one outage produces many unrelated tickets and service-level breaches are noticed only after they happen. This project designs and implements **{{short_title}}**, a multi-tenant support ticket management system that one installation offers to many independent organisations, either as a hosted service or on an organisation's own server. It is built with a Laravel application programming interface, a React single-page application and PostgreSQL, packaged with Docker and deployed to a cloud virtual machine.

Four operational decisions are made by the system's own simple algorithms, and each decision is explained to the agent: a weighted priority score over impact, urgency, customer tier and waiting time; assignment to the eligible agent with the lowest load relative to capacity; duplicate detection by comparing the word sets of tickets; and a service-level agreement (SLA) timer that pauses, warns and records breaches on working hours. A reporting module records every change to the main records, shows any record as it was at a past moment and analyses backlog and workload over time. Each organisation's data is kept separate in the application and in the database. The system also provides a media library, email notifications, email-to-ticket, a REST API with signed webhooks and a management dashboard.

Manual testing of the main workflows and of each algorithm with worked examples confirmed that the system behaves as specified.

**Keywords:** helpdesk, multi-tenancy, ticket prioritisation, agent assignment, duplicate detection, service level agreement
