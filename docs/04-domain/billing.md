# Billing: plans, subscriptions and receipt payments

Decided in [ADR-0025](../adr/0025-plans-subscriptions-and-receipts.md). Module `app/Modules/Billing`; the console side of workspaces, admins and sign-up is in `app/Modules/Platform`.

## Model

| Table | Plane | What it holds |
|---|---|---|
| `plans` | control | code, name, kind (`trial`/`paid`), price in minor units, currency, period in months or trial days, active, order. At most one active trial plan. |
| `subscriptions` | central, keyed by `tenant_id` (no RLS) | one per workspace: plan and `ends_at`; `reminders` sent for that end date |
| `subscription_payments` | central, keyed by `tenant_id` (no RLS) | plan, periods, amount, date paid, method, reference, note, receipt media id, review (`pending → approved | rejected`), the period an approval paid for |
| `workspace_signups` | control | a self sign-up waiting for its email link (password hashed) |
| `platform_invitations`, `platform_password_reset_tokens` | control | platform admin accounts |

Workspace endpoints filter the central rows by the resolved workspace themselves; isolation tests cover them (`TenantModelInventory::UNSCOPED_TABLES_WITH_TENANT_ID`).

## State

`SubscriptionStatus` (PHP) and `SubscriptionStateSql` (lists, counts) derive the state at a moment:

| State | When | Effect |
|---|---|---|
| `trialing` / `active` | before `ends_at` (trial or paid plan) | none; banner from 7 days before the end |
| `grace` | `ends_at` ≤ now < `ends_at` + grace days (platform setting, 7) | banner |
| `expired` | from the end of grace | the tenant API refuses writes with 403 `workspace_read_only` (`EnsureSubscriptionWritable`); sign-out, preferences, notifications, reports and exports, receipt uploads and payments stay open |
| `none` | no subscription row | unmanaged, never limited |

`billing:remind` (daily 06:00) emails the billing people (`billing.manage`) 7, 3 and 1 days before the end, when grace starts and when the workspace becomes read-only, once per end date.

## Payments

1. The workspace pays outside the app (bank transfer, wallet, cash) using the platform's payment instructions, uploads the receipt (upload purpose `receipt`: image or PDF into the system Billing folder) and sends plan, periods, amount, date, method and reference (`POST /v1/billing/payments`). At most three wait at a time.
2. Platform admins are emailed; the console's Payments queue shows the receipt and flags an amount that differs from the plan price.
3. **Approve** extends the subscription by periods × the plan's months from the later of now and the current end, and switches the plan; **reject** needs a reason. Both are single, locked decisions (409 `already_reviewed` the second time) and email the workspace.
4. An admin can also record a payment received outside the app; it is approved at once (no receipt file: MVP-SHORTCUT, V1-PL-21).

Receipts are linked through `mediables` (`subscription_payment`, role `receipt`): they cannot be purged while the payment exists and open only for `billing.manage`.

## Provisioning

`ProvisionTenant` starts every new workspace on the active trial plan, or on a given paid plan for some periods (console). Self sign-up (`/v1/signup`, `/v1/signup/verify`) provisions on the trial with an active owner once the emailed link is followed; the platform setting `signup.enabled` closes it. Existing workspaces were backfilled on Standard for a year; the demo workspaces get a year of Standard, two approved payments and one receipt to review at every reset.

## Dashboard

`GET /platform-api/dashboard`: workspaces by status and subscription state, new workspaces per week (console or sign-up), active people and tickets in the last 30 days (counted per workspace in its own context, cached five minutes: MVP-SHORTCUT, V1-PL-20), approved revenue per month, receipts to review, subscriptions ending in 14 days, newest workspaces.
