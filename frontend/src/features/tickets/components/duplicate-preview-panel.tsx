import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { copy, fill } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { duplicateQueries, isRateLimited } from '../api/duplicate-queries'

export function DuplicatePreviewPanel({
  title,
  description,
  active,
}: {
  title: string
  description: string
  active: boolean
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const [input, setInput] = useState({ title: '', description: '' })
  useEffect(() => {
    const timer = window.setTimeout(
      () => setInput({ title: title.trim(), description: description.trim() }),
      350,
    )
    return () => window.clearTimeout(timer)
  }, [title, description])
  const ready =
    active &&
    tenantId !== '' &&
    input.title.length >= 3 &&
    input.description.length >= 3 &&
    input.title === title.trim() &&
    input.description === description.trim()
  const preview = useQuery({
    ...duplicateQueries.preview(tenantId, input.title, input.description),
    enabled: ready,
  })

  // A throttled preview says nothing: the hint stays, and the next edit asks again.
  const throttled = preview.isError && isRateLimited(preview.error)

  return (
    <section className="rounded-lg border border-border p-4 bg-surface" aria-live="polite">
      <h2 className="text-sm font-medium">{copy.tickets.detail.duplicates}</h2>
      {!ready || throttled ? (
        <p className="mt-1 text-sm text-muted-foreground">{copy.tickets.duplicates.previewHint}</p>
      ) : null}
      {ready && preview.isPending ? (
        <p className="mt-1 text-sm text-muted-foreground">{copy.tickets.duplicates.checking}</p>
      ) : null}
      {ready && preview.isError && !throttled ? (
        <p className="mt-1 text-sm text-destructive">{copy.tickets.duplicates.previewFailed}</p>
      ) : null}
      {ready && preview.data?.matches.length === 0 ? (
        <p className="mt-1 text-sm text-muted-foreground">{copy.tickets.duplicates.none}</p>
      ) : null}
      {ready && preview.data && preview.data.matches.length > 0 ? (
        <ul className="mt-2 space-y-2 text-sm">
          {preview.data.matches.map((match) => (
            <li key={match.ticket_id}>
              <span className="font-medium">
                {fill(copy.tickets.number, { number: match.number })} {match.title}
              </span>
              <span className="ml-2 text-muted-foreground">
                {fill(copy.tickets.duplicates.score, { score: Math.round(match.score * 100) })}
              </span>
              <p className="text-xs text-muted-foreground">
                {fill(copy.tickets.duplicates.shared, { words: match.shared_words.join(', ') })}
              </p>
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  )
}
