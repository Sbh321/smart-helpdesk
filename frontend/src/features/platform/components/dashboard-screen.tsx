import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { type ReactNode, useMemo } from 'react'
import { SeriesChart } from '@/components/shared/charts/series-chart'
import { ErrorState } from '@/components/shared/error-state'
import { KpiTile } from '@/components/shared/kpi-tile'
import { PageHeader } from '@/components/shared/page-header'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import { formatMoney } from '@/lib/format/money'
import { type PlatformDashboard, platformDashboardQuery } from '../api'
import { daysLeftText, SubscriptionBadge } from './subscription-badge'

const text = copy.platform.dashboard
const number = new Intl.NumberFormat('en')

/** The console's start page (ADR-0025 §9). */
export function DashboardScreen() {
  const dashboard = useQuery(platformDashboardQuery())

  return (
    <>
      <PageHeader title={text.title} description={text.description} />
      {dashboard.isPending ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-busy="true">
          {Array.from({ length: 8 }, (_, index) => (
            // biome-ignore lint/suspicious/noArrayIndexKey: placeholders have no identity.
            <Skeleton key={index} className="h-28 w-full rounded-card" />
          ))}
        </div>
      ) : dashboard.isError ? (
        <ErrorState error={dashboard.error} onRetry={() => void dashboard.refetch()} />
      ) : (
        <Dashboard data={dashboard.data} />
      )}
    </>
  )
}

function Dashboard({ data }: { data: PlatformDashboard }) {
  const states = data.workspaces.by_subscription
  const revenue = data.revenue[0]
  const growthRows = useMemo(
    () =>
      data.new_workspaces.map((week) => ({
        key: week.week,
        label: formatInZone(`${week.week}T12:00:00Z`, 'UTC', 'd MMM'),
        fullLabel: formatInZone(`${week.week}T12:00:00Z`, 'UTC', "'Week of' d MMM yyyy"),
        values: { console: week.console, signup: week.signup },
      })),
    [data.new_workspaces],
  )
  const revenueRows = useMemo(
    () =>
      (revenue?.months ?? []).map((month) => ({
        key: month.month,
        label: formatInZone(`${month.month}-15T12:00:00Z`, 'UTC', 'MMM'),
        fullLabel: formatInZone(`${month.month}-15T12:00:00Z`, 'UTC', 'MMMM yyyy'),
        values: { amount: Math.round(month.amount_minor / 100) },
      })),
    [revenue],
  )

  return (
    <div className="flex flex-col gap-6">
      <section aria-label={text.title} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiTile
          compact
          label={text.workspaces}
          value={number.format(data.workspaces.total)}
          footer={
            <span className="text-muted-foreground text-xs">
              {fill(text.workspacesHint, {
                active: data.workspaces.active,
                suspended: data.workspaces.suspended,
              })}
            </span>
          }
        />
        <KpiTile compact label={text.trialing} value={number.format(states.trialing)} />
        <KpiTile compact label={text.paying} value={number.format(states.active)} />
        <KpiTile
          compact
          label={text.attention}
          value={number.format(states.grace + states.expired)}
          footer={
            <Link
              to="/platform/tenants"
              search={{ subscription: 'grace,expired' }}
              className="text-primary text-xs hover:underline"
            >
              {copy.platform.nav.workspaces}
            </Link>
          }
        />
        <KpiTile
          compact
          label={text.pending}
          value={number.format(data.payments.pending)}
          footer={
            <Link to="/platform/payments" className="text-primary text-xs hover:underline">
              {copy.platform.nav.payments}
            </Link>
          }
        />
        <KpiTile
          compact
          label={text.users}
          value={number.format(data.inside.active_users)}
          footer={<span className="text-muted-foreground text-xs">{text.usersHint}</span>}
        />
        <KpiTile compact label={text.tickets} value={number.format(data.inside.tickets_30d)} />
        <KpiTile
          compact
          label={text.revenue}
          value={revenue ? formatMoney(revenue.this_month_minor, revenue.currency) : formatMoney(0, 'NPR')}
          footer={
            <span className="text-muted-foreground text-xs">
              {revenue
                ? fill(text.revenueHint, { amount: formatMoney(revenue.last_month_minor, revenue.currency) })
                : text.noRevenue}
            </span>
          }
        />
      </section>

      <div className="grid gap-4 xl:grid-cols-2">
        <Panel title={text.growth}>
          <SeriesChart
            kind="stacked_bar"
            time
            label={text.growthLabel}
            rows={growthRows}
            measures={[
              { key: 'console', label: text.console, unit: 'count' },
              { key: 'signup', label: text.signup, unit: 'count' },
            ]}
          />
        </Panel>
        <Panel title={revenue ? `${text.revenueChart} (${revenue.currency})` : text.revenueChart}>
          {revenue ? (
            <SeriesChart
              kind="bar"
              time
              label={fill(text.revenueLabel, { currency: revenue.currency })}
              rows={revenueRows}
              measures={[{ key: 'amount', label: revenue.currency, unit: 'count' }]}
            />
          ) : (
            <p className="text-muted-foreground text-sm">{text.noRevenue}</p>
          )}
        </Panel>
      </div>

      <div className="grid gap-4 xl:grid-cols-2">
        <Panel title={text.endingSoon}>
          {data.ending_soon.length === 0 ? (
            <p className="text-muted-foreground text-sm">{text.endingSoonEmpty}</p>
          ) : (
            <Table aria-label={text.endingSoon}>
              <TableHeader>
                <TableRow>
                  <TableHead>{copy.platform.columns.name}</TableHead>
                  <TableHead>{copy.platform.columns.subscription}</TableHead>
                  <TableHead>{copy.platform.columns.ends}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.ending_soon.map((row) => (
                  <TableRow key={row.workspace.id}>
                    <TableCell>
                      <Link
                        to="/platform/tenants/$tenantId"
                        params={{ tenantId: row.workspace.id }}
                        className="font-medium text-primary hover:underline"
                      >
                        {row.workspace.name}
                      </Link>
                    </TableCell>
                    <TableCell>
                      <span className="flex flex-col">
                        <SubscriptionBadge state={row.state} />
                        <span className="text-muted-foreground text-xs">
                          {[row.plan, daysLeftText({ state: row.state, days_left: row.days_left })]
                            .filter(Boolean)
                            .join(' · ')}
                        </span>
                      </span>
                    </TableCell>
                    <TableCell className="tabular-nums">
                      {formatInZone(row.ends_at, 'UTC', 'd MMM yyyy')}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Panel>
        <Panel title={text.recent}>
          <ul className="divide-y divide-border text-sm">
            {data.recent_workspaces.map((workspace) => (
              <li key={workspace.id} className="flex items-center justify-between gap-3 py-2">
                <Link
                  to="/platform/tenants/$tenantId"
                  params={{ tenantId: workspace.id }}
                  className="min-w-0 truncate font-medium text-primary hover:underline"
                >
                  {workspace.name}
                </Link>
                <span className="shrink-0 text-muted-foreground text-xs">
                  {workspace.source === 'signup' ? text.viaSignup : text.viaConsole}
                  {workspace.created_at ? ` · ${formatInZone(workspace.created_at, 'UTC', 'd MMM')}` : ''}
                </span>
              </li>
            ))}
          </ul>
        </Panel>
      </div>
      <p className="text-muted-foreground text-xs">
        {fill(text.measured, { time: formatInZone(data.inside.measured_at, 'UTC', 'HH:mm') })}
      </p>
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section
      aria-label={title}
      className="flex flex-col gap-3 rounded-card border border-border bg-surface p-4 shadow-1"
    >
      <h2 className="font-semibold text-base">{title}</h2>
      {children}
    </section>
  )
}
