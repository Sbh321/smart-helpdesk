# Chapter 5: Conclusion and Future Recommendations

## 5.1 Conclusion

This project built Smart Helpdesk, a multi-tenant support ticket management system in which one installation serves many organisations while keeping each organisation's data separate in the application and in the database. The system covers the full ticket workflow, a media library, email in both directions, a dashboard, reports with record history, a REST API and signed webhooks, and it runs on a cloud virtual machine or an organisation's own server.

All objectives of Section 1.3 were met. Four simple algorithms make the key decisions: a weighted priority score, least-loaded agent assignment, word-based duplicate detection and SLA timers on working hours, and each decision is explained to the agent. Manual unit and system testing confirmed that the features and the algorithms behave as specified. The main lessons were that explanations make automatic decisions easier to trust, that time handling is the hardest part of SLA management, and that data separation between organisations must be checked on every screen and API.

## 5.2 Future Recommendations

- **Load testing and monitoring** of the ticket list, ticket creation and dashboard under many users before wider use.
- **Stronger algorithms**, such as a priority score that considers the SLA deadline and duplicate detection that recognises different words with the same meaning.
- **A customer portal** where contacts follow their tickets, with live chat and messaging channels.
- **Weights learned from history**, fitting the priority and assignment settings from resolved tickets.
- **Mobile layouts** so that agents can work tickets from their phones.
