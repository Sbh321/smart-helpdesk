# Milestone 6 — Platform administration and billing

**Milestone status:** `[x]` Done — 9 of 9 tasks (2026-09-25). The AWS release waits for the owner.

Goal: the platform can be run as a service without an online payment provider. Platform admins create and manage workspaces, plans and other admins from the console; workspaces start on a free trial, pay for a period by uploading a receipt that an admin verifies, and become read-only after a grace period if they do not; people can sign up for a workspace themselves; the console opens on a dashboard of the platform's numbers.

Owner decisions (2026-09-25), recorded in [ADR-0025](../docs/adr/0025-plans-subscriptions-and-receipts.md): the workspace owner uploads the receipt and an admin approves or rejects it; grace, then read-only; console creation and public self sign-up; all platform admins equal.

## Order

```text
M6-01 → M6-02 → M6-03 ─┐
M6-04 ─────────────────┼→ M6-06 → M6-07 → M6-08 → M6-09
M6-05 ─────────────────┘
```

---

### `[x]` M6-01 Plans and subscriptions — M
- **Do:** `plans` and `subscriptions` (central; `subscriptions.tenant_id` unique, foreign key, cascade); seed *Free trial* and *Standard*; backfill existing workspaces on *Standard* for a year; `SubscriptionState` (derived state and days left) and its SQL twin; `ProvisionTenant` starts a new workspace on the active trial plan (or a given plan and periods); platform settings `billing.grace_days`; platform API for plans (list, create, update, archive) and for a workspace's subscription (change plan, set the end date).
- **Acceptance:** the state is right at any moment without a job; only one active trial plan; a plan in use cannot be archived away from its subscriptions; every change is audited.
- **Depends:** —
- **Tests:** state table (trialing, active, grace, expired at the boundaries) for PHP and SQL; provisioning; plan and subscription endpoints; isolation (`UNSCOPED_TABLES_WITH_TENANT_ID`).
- **Docs:** 04-domain billing page, 08-database, 07-api platform, terminology.

### `[x]` M6-02 Payments with receipts — L
- **Do:** `subscription_payments`; the *Billing* media folder and upload purpose `receipt` (images, PDF; `billing.manage`); `billing.manage` in the permission catalogue (owner, admin); `GET /v1/billing` (plan, state, days left, payments, plans on offer) and `POST /v1/billing/payments` (plan, periods, amount, date, method, reference, note, receipt media id); platform `GET /payments` (filters), `GET /payments/{id}`, `GET /payments/{id}/receipt`, `POST /payments/{id}/approve`, `POST /payments/{id}/reject {reason}`, `POST /tenants/{id}/payments` (recorded by an admin); approval extends the subscription under a lock; notifications to owners and admins.
- **Acceptance:** a workspace sees and submits only its own payments; approving twice changes nothing; a receipt in use cannot be trashed; amounts are integers in minor units.
- **Depends:** M6-01
- **Tests:** submit, approve (extension from now and from a future end date), reject, double approval, cross-tenant reads, receipt link and download URL, notifications.
- **Docs:** billing page, media purposes, API.

### `[x]` M6-03 Reminders and read-only workspaces — M
- **Do:** daily `billing:remind` (7, 3, 1 days before the end, grace started, read-only), each sent once per period; `EnsureSubscriptionWritable` on the tenant API with its allow-list; the subscription summary on `GET /v1/me`.
- **Acceptance:** an expired workspace can read, export, sign out, pay and upload a receipt, and nothing else; approval lifts it on the next request.
- **Depends:** M6-02
- **Tests:** middleware (each allowed and refused route kind, API clients too), reminder schedule and deduplication.
- **Docs:** billing page, scheduler, error codes.

### `[x]` M6-04 Platform admin accounts — L
- **Do:** `platform_users.is_active`, `deactivated_at`, nullable password; `platform_invitations`; `platform_password_reset_tokens` and the `platform_users` broker; invite, resend, revoke, accept; forgot and reset password; own profile and password; deactivate and reactivate (not oneself, never the last active admin), which ends sessions and passes.
- **Acceptance:** the same neutral answer for unknown addresses; links single use and time limited; a deactivated admin is signed out on the next request.
- **Depends:** —
- **Tests:** each flow and each refusal; throttles; audit rows.
- **Docs:** security.md, 07-api platform, operations runbook (first admin).

### `[x]` M6-05 Self sign-up — L
- **Do:** `workspace_signups`; `GET /v1/signup/address?slug=`, `POST /v1/signup` (honeypot, throttles, 202), verification mail, `POST /v1/signup/verify {token}` provisioning on the trial plan with an active owner; platform setting `signup.enabled`; SPA `/signup` and `/signup/verify`; the website's calls to action.
- **Acceptance:** nothing is created before verification; a used or expired link says so; a taken or reserved address is refused before and at verification.
- **Depends:** M6-01
- **Tests:** feature tests for each path; browser tests for the form and verification.
- **Docs:** 07-api authentication, security.md, landing.

### `[x]` M6-06 Console: workspaces, plans, payments, admins, settings — XL
- **Do:** console navigation; *New workspace*; workspace detail (details, status, subscription, payments, owner); plans; the payments queue with the receipt in the lightbox, approve and reject; admins (invite, resend, deactivate); settings (sign-up, grace days); account (name, password); forgot, reset and invitation screens on the platform frame.
- **Depends:** M6-01…M6-05
- **Tests:** console browser tests per page; accessibility scans.
- **Docs:** frontend.md.

### `[x]` M6-07 Workspace billing page and banner — M
- **Do:** Settings → Billing (plan, state, days left, pay: plan, periods, amount, method, reference, receipt through the upload field; history); the shell banner (trial ending, grace, read-only).
- **Depends:** M6-02, M6-03
- **Tests:** browser tests; accessibility scan.

### `[x]` M6-08 Platform dashboard — M
- **Do:** `GET /platform-api/dashboard`; the console's start page with KPI tiles, charts and lists.
- **Depends:** M6-02
- **Tests:** endpoint figures on a known dataset; browser test.

### `[x]` M6-09 Demo data, E2E and suggestions — S
- **Do:** demo subscriptions and payments (one pending); an E2E journey (sign-up → trial → receipt → approval); the list of further platform features in the backlog.
- **Depends:** M6-06, M6-07, M6-08

---

## Done (2026-09-25)

- Backend: `Billing` module (plans, subscriptions, payments, `SubscriptionStatus` and its SQL twin, `EnsureSubscriptionWritable`, `billing:remind`, receipts as Billing-folder media), platform admin accounts, self sign-up, settings (sign-up, grace days, payment instructions) and the dashboard. `billing.manage` in the catalogue; `signup` reserved. Tests: `tests/Feature/Billing/{Subscription,Payment,ReadOnly}Test.php`, `tests/Feature/Platform/{PlatformAdminAccounts,Signup,PlatformDashboard}Test.php`; route protection, isolation inventory and an architecture rule (Billing never imports Platform); 2 068 backend tests pass.
- Frontend: the console's six tabs, account and recovery screens; Billing page and banner; sign-up and verification; website calls to action. Tests: 16 console, 6 billing and 4 sign-up browser tests with axe scans; 364 browser and 52 E2E tests pass.
- Demo: both workspaces on Standard for a year from each reset, two approved payments and one receipt to review, sample payment instructions.

## Suggestions for the platform (not built)

Ordered by value for running the service; each is small enough for one task.

1. **Invoices and receipts from the platform**: a numbered PDF invoice per approved payment (VAT/PAN fields for Nepal), emailed and listed on the Billing page.
2. **Online payment** (eSewa, Khalti, Stripe) filling the same payment record and approving itself on the provider's callback (V1-PL-03).
3. **Plan limits** (agents, tickets per month, storage) enforced with Pennant feature flags and shown as usage bars on the Billing page.
4. **Coupons and discounts**, and prices per currency.
5. **Platform audit log view** in the console (the rows already exist with no tenant) and a per-workspace activity timeline.
6. **Impersonation** ("sign in as this workspace's owner" for support), time-limited, audited and announced to the workspace.
7. **Two-factor sign-in** for platform admins (TOTP) and, later, roles (billing only, support only).
8. **Churn and cohort reports**: trial-to-paid conversion, monthly recurring revenue, workspaces lapsing.
9. **Announcements**: a platform-wide banner or email to every workspace (maintenance windows, new features).
10. **Data export and deletion** for a workspace that leaves (archive, download everything, delete after 30 days).
11. **Support inbox for workspaces**: owners open a request to the platform from inside their workspace.
12. **Health per workspace**: queue lag, failed webhooks and mail bounces, surfaced on the workspace page.
