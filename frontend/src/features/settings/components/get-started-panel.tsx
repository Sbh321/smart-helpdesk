import { Link } from '@tanstack/react-router'
import { ArrowRightIcon, XIcon } from 'lucide-react'
import { useState } from 'react'
import { CopyableValue } from '@/components/shared/copyable-value'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import { useEmailSettings } from '@/features/mail'
import { hasPermission, useSession } from '@/lib/auth'

const text = copy.getStarted

/** The setup pages the panel links to, each shown only with the permission its page needs. */
const STEPS = [
  { to: '/$workspace/settings/users', permission: 'users.manage', ...text.steps.users },
  { to: '/$workspace/settings/agents', permission: 'agents.view', ...text.steps.agents },
  { to: '/$workspace/settings/categories', permission: 'tickets.view', ...text.steps.categories },
  { to: '/$workspace/settings/sla', permission: 'tickets.view', ...text.steps.sla },
  { to: '/$workspace/settings/branding', permission: 'settings.manage', ...text.steps.branding },
  { to: '/$workspace/settings/api-clients', permission: 'integrations.manage', ...text.steps.integrations },
] as const

const storageKey = (tenantId: string) => `shp.getStarted.hidden.${tenantId}`

function readHidden(tenantId: string): boolean {
  try {
    return window.localStorage.getItem(storageKey(tenantId)) === '1'
  } catch {
    return false
  }
}

/**
 * The dashboard's first block for whoever runs the workspace (`mail.manage`: owners and admins): the
 * address customers write to, and links to the setup pages. Hiding it is remembered in this browser;
 * the same facts stay under Settings → Email and in the welcome mail.
 */
export function GetStartedPanel({ workspace }: { workspace: string }) {
  const { session, permissions } = useSession()
  const { allowed, tenantId, query } = useEmailSettings()
  const [hidden, setHidden] = useState(() => (tenantId === '' ? false : readHidden(tenantId)))

  if (!allowed || hidden || tenantId === '') return null

  const hide = () => {
    try {
      window.localStorage.setItem(storageKey(tenantId), '1')
    } catch {
      // Storage blocked: hidden for this visit only.
    }
    setHidden(true)
  }
  const steps = STEPS.filter((step) => hasPermission(permissions, step.permission))

  return (
    <Card role="region" aria-labelledby="get-started-title">
      <CardHeader>
        <CardTitle id="get-started-title">{fill(text.title, { name: session?.tenant.name ?? '' })}</CardTitle>
        <CardDescription>{text.description}</CardDescription>
        <CardAction>
          <Hint label={text.hide}>
            <Button variant="ghost" size="icon" aria-label={text.hide} onClick={hide}>
              <XIcon aria-hidden="true" />
            </Button>
          </Hint>
        </CardAction>
      </CardHeader>
      <CardContent className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <div className="flex flex-col gap-2">
          {query.data ? (
            <CopyableValue
              id="get-started-address"
              label={text.emailLabel}
              value={query.data.intake_address}
            />
          ) : (
            <p className="text-sm text-muted-foreground">
              {query.isError ? text.emailFailed : text.emailLoading}
            </p>
          )}
          <p className="text-sm text-muted-foreground">{text.emailHelp}</p>
          <Link
            to="/$workspace/settings/email"
            params={{ workspace }}
            className="text-sm text-primary underline underline-offset-4"
          >
            {text.emailSettings}
          </Link>
        </div>
        <section aria-labelledby="get-started-steps" className="flex flex-col gap-2">
          <h3 id="get-started-steps" className="font-medium">
            {text.stepsTitle}
          </h3>
          <ol className="flex flex-col divide-y rounded-md border">
            {steps.map((step, index) => (
              <li key={step.to}>
                <Link
                  to={step.to}
                  params={{ workspace }}
                  className="flex items-center gap-3 px-3 py-2.5 text-sm hover:bg-muted focus-visible:bg-muted"
                >
                  <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium tabular-nums">
                    {index + 1}
                  </span>
                  <span className="flex min-w-0 flex-1 flex-col">
                    <span className="font-medium">{step.title}</span>
                    <span className="text-muted-foreground">{step.body}</span>
                  </span>
                  <ArrowRightIcon aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                </Link>
              </li>
            ))}
          </ol>
        </section>
      </CardContent>
    </Card>
  )
}
