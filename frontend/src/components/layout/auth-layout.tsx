import { CheckIcon, ClockIcon, SparklesIcon, UserCheckIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { BrandMark } from '@/components/shared/brand-mark'
import { MAIN_CONTENT_ID, SkipLink } from '@/components/shared/skip-link'
import { ThemeToggle } from '@/components/shared/theme-toggle'
import { copy } from '@/copy/en'
import { useRuntimeConfig } from '@/lib/config'

/**
 * The frame of every pre-authentication page (M5-03, docs/06-design-system/page-patterns.md
 * §Pre-authentication pages): the form column with one `h1` and nothing to guess at, and from `lg` a
 * brand panel that says what the product does. The panel is supporting content, so it is an `aside`
 * after the form in reading order; below `lg` it is left out and the form has the screen to itself.
 */
export function AuthLayout({
  title,
  description,
  eyebrow,
  children,
  variant = 'tenant',
}: {
  title: string
  description?: ReactNode
  /** Above the heading: the workspace chip on the sign-in page. */
  eyebrow?: ReactNode
  children: ReactNode
  variant?: 'tenant' | 'platform'
}) {
  const { platformDomain } = useRuntimeConfig()

  return (
    <div className="grid min-h-dvh bg-background text-foreground lg:grid-cols-2">
      <SkipLink />
      <div className="flex min-h-dvh flex-col">
        <header className="flex items-center justify-between gap-4 px-6 py-5 sm:px-10">
          <a
            href={`https://${platformDomain}`}
            aria-label={copy.authFrame.home}
            className="rounded-control focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-offset-4"
          >
            <BrandMark className="whitespace-nowrap" />
          </a>
          <ThemeToggle compact />
        </header>

        <main
          id={MAIN_CONTENT_ID}
          tabIndex={-1}
          className="flex flex-1 items-center justify-center px-6 pb-16 outline-none sm:px-10"
        >
          <div className="w-full max-w-sm">
            {eyebrow ? <div className="mb-6">{eyebrow}</div> : null}
            <h1 className="text-balance font-semibold text-3xl tracking-tight">{title}</h1>
            {description ? (
              <p className="mt-2 text-pretty text-base text-muted-foreground">{description}</p>
            ) : null}
            <div className="mt-8">{children}</div>
          </div>
        </main>

        <footer className="px-6 py-5 text-muted-foreground text-xs sm:px-10">
          © {new Date().getFullYear()} {copy.app.name}
        </footer>
      </div>

      <BrandPanel variant={variant} />
    </div>
  )
}

function BrandPanel({ variant }: { variant: 'tenant' | 'platform' }) {
  const headline = variant === 'platform' ? copy.authFrame.platformHeadline : copy.authFrame.tenantHeadline
  const points = variant === 'platform' ? copy.authFrame.platformPoints : copy.authFrame.tenantPoints

  return (
    <aside className="relative hidden overflow-hidden border-border border-l bg-muted lg:flex lg:flex-col lg:justify-center">
      <div aria-hidden="true" className="absolute inset-0 bg-brand-glow" />
      <div aria-hidden="true" className="absolute inset-0 bg-brand-grid" />

      <div className="relative mx-auto flex w-full max-w-lg flex-col gap-10 px-12 py-16">
        <div>
          <h2 className="text-balance font-semibold text-3xl tracking-tight">{headline}</h2>
          <ul className="mt-6 flex flex-col gap-3 text-base text-muted-foreground">
            {points.map((point) => (
              <li key={point} className="flex gap-3">
                <CheckIcon aria-hidden="true" className="mt-1 size-4 shrink-0 text-primary" />
                <span>{point}</span>
              </li>
            ))}
          </ul>
        </div>

        {variant === 'tenant' ? <TicketPreview /> : null}
      </div>
    </aside>
  )
}

/**
 * An example ticket drawn with the application's own tokens: what an agent sees, and why. Decorative
 * (the points above say the same in words), so it is hidden from assistive technology.
 */
function TicketPreview() {
  const text = copy.authFrame.preview

  return (
    <div aria-hidden="true" className="rounded-modal border border-border bg-surface p-5 shadow-3">
      <div className="flex items-center justify-between text-muted-foreground text-xs">
        <span>{text.label}</span>
        <span className="font-mono">{text.number}</span>
      </div>
      <p className="mt-2 font-medium text-base">{text.title}</p>

      <div className="mt-4 flex flex-wrap items-center gap-2 text-xs">
        <span className="rounded-badge bg-priority-p2 px-2 py-0.5 font-medium text-priority-p2-foreground">
          {text.priority}
        </span>
        <span className="inline-flex items-center gap-1 text-muted-foreground">
          <SparklesIcon className="size-3.5" />
          {text.why}
        </span>
      </div>

      <div className="mt-4 flex flex-col gap-2 border-border border-t pt-4 text-sm">
        <span className="inline-flex items-center gap-2">
          <UserCheckIcon className="size-4 text-primary" />
          {text.assigned}
        </span>
        <span className="inline-flex items-center gap-2">
          <ClockIcon className="size-4 text-sla-ok" />
          {text.sla}
        </span>
        <span className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
          <span className="block h-full w-2/5 rounded-full bg-sla-ok" />
        </span>
      </div>
    </div>
  )
}
