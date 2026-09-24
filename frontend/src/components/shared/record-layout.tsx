import { HistoryIcon, Undo2Icon } from 'lucide-react'
import type { ReactNode } from 'react'
import { PageHeader } from '@/components/shared/page-header'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import { useRecordView } from '@/lib/record-view'

export interface RecordLayoutProps {
  /** The way back to the list (a `BackLink`). */
  eyebrow?: ReactNode
  /** What the record is, in the singular: "Contact", "Ticket", "Agent". */
  kind: string
  /** The record's name or number and subject. */
  title: string
  /** One line under the title: the email of a contact, the domain of an organisation. */
  description?: string
  /** State badges beside the identity (status, archived). Current state, so not in the historical view. */
  badges?: ReactNode
  /** Actions that change the record. Never rendered in the historical view. */
  actions?: ReactNode
  /** The live record: details, tabs, panels. */
  children: ReactNode
  /** The History section, which is the whole page while an instant is chosen. */
  history?: ReactNode
  timeZone: string
}

/**
 * The frame of every record page (roadmap M4-10, docs/06-design-system/page-patterns.md §Record page):
 * identity header (what it is, its name, its state), the record's actions, then its content.
 *
 * With `?as_of=` in the URL the page is the record **as it was**: a banner that stays in view names the
 * instant, the header loses every action that would write, and the content is the History section (the
 * state at that instant, its differences from now, and the timeline), not the live forms and panels,
 * so nothing on the screen can be mistaken for, or edited as, the current record. "Back to now" is
 * one click and restores the section the reader came from.
 */
export function RecordLayout({
  eyebrow,
  kind,
  title,
  description,
  badges,
  actions,
  children,
  history,
  timeZone,
}: RecordLayoutProps) {
  const { asOf, setAsOf } = useRecordView('details')
  const historical = asOf !== null && history !== undefined
  const text = copy.recordLayout
  const identity = description ? `${kind} · ${description}` : kind

  if (historical) {
    const date = formatInZone(asOf, timeZone, 'd MMM yyyy, HH:mm:ss')
    return (
      <div className="flex flex-col gap-4" data-record-mode="historical">
        <section
          aria-label={text.bannerLabel}
          className="sticky top-0 z-10 flex flex-wrap items-center gap-3 rounded-lg border-2 border-warning bg-surface px-4 py-3 shadow-sm"
        >
          <HistoryIcon aria-hidden="true" className="size-5 shrink-0 text-warning" />
          <p className="min-w-0 flex-1 text-sm">
            <span className="font-semibold">
              {fill(text.bannerTitle, { kind: kind.toLowerCase(), date })}
            </span>{' '}
            <span className="text-muted-foreground">{text.bannerBody}</span>
          </p>
          <Button type="button" onClick={() => setAsOf(null)}>
            <Undo2Icon aria-hidden="true" />
            {text.backToNow}
          </Button>
        </section>
        <PageHeader
          eyebrow={eyebrow}
          title={title}
          description={fill(text.historicalIdentity, { identity, date })}
        />
        {history}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4" data-record-mode="live">
      <PageHeader
        eyebrow={eyebrow}
        title={title}
        description={identity}
        actions={
          badges || actions ? (
            <div className="flex flex-wrap items-center gap-2">
              {badges}
              {actions}
            </div>
          ) : undefined
        }
      />
      {children}
    </div>
  )
}
