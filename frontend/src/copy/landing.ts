/**
 * The landing site's words (M5-04). Kept apart from `en.ts` so the application bundle does not carry
 * marketing copy. Every claim here is something the product does today (docs/02-product, the roadmap
 * milestones); no customers, testimonials, statistics or prices.
 */
export const landing = {
  meta: {
    title: 'Smart Helpdesk: support tickets with explainable automation',
    description:
      'A multi-tenant helpdesk that prioritises every ticket, assigns it to the agent with the most room, spots duplicates and keeps SLAs in view, and shows the reason for each decision.',
  },
  nav: {
    label: 'Main',
    skip: 'Skip to content',
    home: 'Smart Helpdesk home',
    features: 'Features',
    how: 'How it works',
    faq: 'FAQ',
    developers: 'Developers',
    signIn: 'Sign in',
    openApp: 'Open the app',
    menu: 'Menu',
    theme: 'Switch between light and dark',
  },
  hero: {
    eyebrow: 'Multi-tenant helpdesk',
    title: 'Support tickets, prioritised and assigned with the reason shown',
    body: 'Smart Helpdesk brings web, email and API requests into one queue, scores each ticket, hands it to the agent with the most room, flags likely duplicates and keeps every SLA in view. Each decision is explained in plain words, so your team can trust it and change it.',
    primary: 'Open the app',
    secondary: 'Find your workspace',
    imageAlt:
      'The ticket queue in Smart Helpdesk: tickets with their status, priority, time left on the SLA and assignee.',
  },
  channels: {
    heading: 'Requests arrive from',
    items: [
      { title: 'Web app', body: 'Agents create and work tickets in one place.' },
      { title: 'Email', body: 'Mail to your support address becomes a ticket; replies thread back.' },
      { title: 'REST API', body: 'Your systems create tickets with OAuth 2.0 client credentials.' },
      { title: 'Webhooks', body: 'Signed events tell your systems what changed.' },
    ],
  },
  features: {
    eyebrow: 'Automation you can read',
    heading: 'Four decisions the helpdesk makes for you, each with its reason',
    body: 'Every rule is simple on purpose, tuned in settings and shown on the ticket. Nothing happens in a black box.',
    priority: {
      title: 'Explainable priority',
      body: 'A score from impact, urgency, customer tier and waiting time decides P1 to P4. Each factor shows its share, and a manager can override with a reason.',
      // Default weights (0.35 urgency, 0.40 impact, 0.15 tier, 0.10 age over 72 h): urgency 4, impact 3,
      // a premium customer and 18 hours waiting.
      rows: [
        { label: 'Urgency 4 of 4', value: 35.0 },
        { label: 'Impact 3 of 4', value: 26.7 },
        { label: 'Premium customer', value: 7.5 },
        { label: 'Waiting 18 hours', value: 2.5 },
      ],
      total: 'Score 71.7',
      level: 'P2 High',
    },
    assignment: {
      title: 'Fair assignment',
      body: 'The ticket goes to the eligible agent with the lowest load for their capacity. Agents who are away, off shift or missing a skill are listed with the reason.',
      rows: [
        { name: 'Asha Gurung', detail: '2 of 8 open', chosen: true },
        { name: 'Deepa Karki', detail: '5 of 10 open', chosen: false },
        { name: 'Bikram Rai', detail: 'Missing skill: billing', chosen: false, excluded: true },
      ],
      chosen: 'Assigned',
      excluded: 'Not eligible',
    },
    duplicates: {
      title: 'Duplicate detection',
      body: 'While a ticket is written, similar open tickets appear with the words they share, so one outage stays one ticket.',
      newTicket: 'New: Cannot log in to the billing portal',
      // Worked out with the real word set: 3 shared words of 7 distinct gives Jaccard 0.43 (threshold 0.35).
      match: '#1032 Billing portal log in fails after the password reset',
      similarity: 'Similarity 0.43',
      shared: ['billing', 'portal', 'log'],
    },
    sla: {
      title: 'SLA monitoring',
      body: 'First response and resolution timers run on your working hours and holidays, pause while you wait for the customer, warn before the deadline and notify on a breach.',
      running: 'Running',
      paused: 'Paused: waiting for the customer',
      warning: 'Warning at 75 %',
      due: 'Due',
    },
  },
  more: {
    heading: 'Everything a support team needs around the queue',
    items: [
      {
        title: 'Isolated workspaces',
        body: 'Each organisation gets its own workspace; the database enforces the separation.',
      },
      {
        title: 'Reports and history',
        body: 'Backlog, workload and SLA reports, and any ticket as it was at a past moment.',
      },
      {
        title: 'Roles and permissions',
        body: 'Owners, admins, managers, agents and developers, each with exactly what they need.',
      },
      {
        title: 'Media library',
        body: 'Attachments and images kept per workspace with previews and safe downloads.',
      },
      { title: 'Audit log', body: 'Who changed what and when, for settings, users and tickets.' },
      {
        title: 'Light, dark and keyboard',
        body: 'Both themes, full keyboard use and screen-reader support throughout.',
      },
    ],
  },
  showcase: {
    heading: 'Every decision explained on the ticket',
    body: 'Status, priority and the time left on each SLA sit at the top of the ticket, with the reason for the priority one click away. Replies and internal notes stay in one conversation.',
    imageAlt:
      'A ticket in Smart Helpdesk: its title, status, P1 priority, a breached resolution timer, the conversation and the requester details.',
  },
  how: {
    heading: 'How it works',
    steps: [
      {
        title: 'A request arrives',
        body: 'From the web app, an email to your support address or your own system through the API.',
      },
      { title: 'It is scored', body: 'Priority is calculated at once and again as the ticket waits.' },
      {
        title: 'It is assigned',
        body: 'To the eligible agent with the most room, or left for a manager when nobody fits.',
      },
      {
        title: 'It is kept on time',
        body: 'SLA timers warn before a deadline and notify when one is missed, until the ticket is resolved.',
      },
    ],
  },
  faq: {
    heading: 'Questions',
    body: 'What teams usually ask first.',
    items: [
      {
        question: 'Which channels does it handle?',
        answer:
          'Tickets come from the web app, from email sent to your workspace’s support address, and from your own systems through the REST API. Signed webhooks tell your systems when tickets change.',
      },
      {
        question: 'How is a ticket’s priority decided?',
        answer:
          'A weighted score from impact, urgency, the customer’s tier and how long the ticket has waited. Administrators set the weights and thresholds; managers can override a ticket’s priority with a reason. The ticket always shows how the score was made.',
      },
      {
        question: 'Can we see why a ticket went to a particular agent?',
        answer:
          'Yes. The assignment shows every agent considered, their load against their capacity, and why anyone was left out: away, off shift, missing a skill or at capacity.',
      },
      {
        question: 'Is our data kept apart from other organisations?',
        answer:
          'Each organisation works in its own workspace. The application scopes every query to the workspace, and PostgreSQL row-level security enforces the same rule in the database; an automated suite tests it.',
      },
      {
        question: 'How do we connect our own systems?',
        answer:
          'Create an API client with the scopes it needs and call the REST API with OAuth 2.0 client credentials. Subscribe to webhooks for ticket events; each delivery is signed. Developers in your workspace can read the full API reference after signing in.',
      },
      {
        question: 'Can we run it on our own server?',
        answer:
          'Yes. The platform runs with Docker Compose on any virtual machine, as a hosted service for many organisations or on one organisation’s own server.',
      },
      {
        question: 'What if I have an account but do not know my workspace?',
        answer:
          'Choose “Find your workspace” and enter your work email. We send a sign-in link for every workspace the address belongs to.',
      },
    ],
  },
  cta: {
    heading: 'Ready when your team is',
    body: 'Sign in to your workspace, or find it by email.',
    primary: 'Open the app',
    secondary: 'Find your workspace',
  },
  footer: {
    tagline: 'Support tickets with explainable automation.',
    product: 'Product',
    developers: 'Developers',
    account: 'Account',
    apiReference: 'API reference',
    apiReferenceNote: 'sign-in required',
    licences: 'Licences',
    rights: 'Smart Helpdesk',
  },
} as const
