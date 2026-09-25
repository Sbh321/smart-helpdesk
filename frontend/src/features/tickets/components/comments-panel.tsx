import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { LockIcon, ReplyIcon, UserIcon } from 'lucide-react'
import { useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { Textarea } from '@/components/ui/textarea'
import { copy } from '@/copy/en'
import { AttachmentsField, type AttachmentUploaderState, MediaChips } from '@/features/media'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { cn } from '@/lib/utils'
import { addComment, type CommentInput, commentQueries } from '../api/comment-queries'
import { SafeCommentMarkdown } from './safe-comment-markdown'

export function CommentsPanel({
  tenantId,
  ticketId,
  timeZone,
}: {
  tenantId: string
  ticketId: string
  timeZone: string
}) {
  const canRead = useCan('tickets.view')
  const canWrite = useCan('tickets.update')
  const canInternal = useCan('comments.internal')
  const client = useQueryClient()
  const [visibility, setVisibility] = useState<CommentInput['visibility']>('public')
  const internalMode = visibility === 'internal'
  const [body, setBody] = useState('')
  const [attachments, setAttachments] = useState<AttachmentUploaderState>({ mediaIds: [], uploading: false })
  // A new key remounts the field: uploads still running are aborted and cannot reach the next comment.
  const [uploadCycle, setUploadCycle] = useState(0)
  const comments = useInfiniteQuery({
    ...commentQueries.list(tenantId, ticketId),
    enabled: canRead && tenantId !== '',
  })
  const entries = comments.data?.pages.flatMap((page) => page.data) ?? []
  const save = useMutation({
    mutationFn: () =>
      addComment(ticketId, {
        body: body.trim(),
        visibility,
        ...(attachments.mediaIds.length > 0 ? { media_ids: attachments.mediaIds } : {}),
      }),
    onSuccess: () => {
      setBody('')
      setAttachments({ mediaIds: [], uploading: false })
      setUploadCycle((current) => current + 1)
      void client.invalidateQueries({ queryKey: queryKeys.tickets.comments(tenantId, ticketId) })
      void client.invalidateQueries({ queryKey: queryKeys.tickets.history(tenantId, ticketId) })
      void client.invalidateQueries({ queryKey: queryKeys.tickets.detail(tenantId, ticketId) })
      void client.invalidateQueries({ queryKey: queryKeys.sla.ticket(tenantId, ticketId) })
      toast.success(copy.comments.saved)
    },
  })

  if (!canRead) return <ForbiddenState />
  return (
    <section className="space-y-4" aria-label={copy.comments.title}>
      {comments.isPending ? (
        <Skeleton className="h-24 w-full" />
      ) : comments.isError ? (
        <ErrorState error={comments.error} onRetry={() => void comments.refetch()} />
      ) : entries.length === 0 ? (
        <p className="text-sm text-muted-foreground">{copy.comments.empty}</p>
      ) : (
        <>
          <ol className="space-y-3">
            {entries.map((comment) => {
              // Three kinds of message, told apart by weight and a marker rather than colour alone
              // (roadmap M4-05): the customer's words, the workspace's reply, and a note the customer
              // never sees.
              const internal = comment.visibility === 'internal'
              const fromContact = comment.author_type === 'contact'
              return (
                <li
                  key={comment.id}
                  className={cn(
                    'rounded-lg border bg-surface p-3 text-sm',
                    // The marker is the edge, the icon and the label, not a tinted surface: a tint
                    // under body text cost contrast (brand-coloured links fell to 4.47:1).
                    internal
                      ? 'border-warning/40 border-s-4 border-s-warning'
                      : fromContact
                        ? 'border-border border-s-4 border-s-muted-foreground/40'
                        : 'border-border',
                  )}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="flex items-center gap-1.5 font-medium">
                      {internal ? (
                        <LockIcon aria-hidden="true" className="size-3.5 text-warning" />
                      ) : fromContact ? (
                        <UserIcon aria-hidden="true" className="size-3.5 text-muted-foreground" />
                      ) : (
                        <ReplyIcon aria-hidden="true" className="size-3.5 text-muted-foreground" />
                      )}
                      {internal
                        ? copy.comments.internal
                        : fromContact
                          ? copy.comments.fromContact
                          : copy.comments.public}
                    </span>
                    <time className="text-muted-foreground" dateTime={comment.created_at ?? undefined}>
                      {comment.created_at ? formatInZone(comment.created_at, timeZone) : ''}
                    </time>
                  </div>
                  <SafeCommentMarkdown body={comment.body} />
                  {comment.attachments.length > 0 ? (
                    <div className="mt-3">
                      <MediaChips items={comment.attachments} label={copy.comments.attachments} />
                    </div>
                  ) : null}
                </li>
              )
            })}
          </ol>
          {comments.hasNextPage ? (
            <Button
              variant="outline"
              disabled={comments.isFetchingNextPage}
              onClick={() => void comments.fetchNextPage()}
            >
              {copy.comments.loadMore}
            </Button>
          ) : null}
        </>
      )}
      {canWrite ? (
        <form
          // The composer wears the mode: an internal note is tinted and marked, so a note meant for
          // colleagues is never mistaken for a message to the customer (roadmap M4-05).
          className={cn(
            'space-y-3 rounded-lg border p-4',
            internalMode
              ? 'border-warning/40 border-s-4 border-s-warning bg-warning/5'
              : 'border-border bg-surface',
          )}
          onSubmit={(event) => {
            event.preventDefault()
            if (body.trim() && !attachments.uploading) save.mutate()
          }}
        >
          {canInternal ? (
            <fieldset className="flex w-fit gap-1 rounded-lg border border-border bg-surface p-1">
              <legend className="sr-only">{copy.comments.visibility}</legend>
              {(['public', 'internal'] as const).map((mode) => (
                <label
                  key={mode}
                  className={cn(
                    'relative flex cursor-pointer items-center gap-1.5 rounded-control px-2.5 py-1 text-sm',
                    'has-focus-visible:outline-2 has-focus-visible:outline-ring has-focus-visible:outline-offset-2',
                    visibility === mode
                      ? mode === 'internal'
                        ? 'bg-warning/15 font-medium text-foreground'
                        : 'bg-muted font-medium text-foreground'
                      : 'text-muted-foreground',
                  )}
                >
                  {/* The input covers its label rather than hiding: it keeps native radio semantics and
                      stays a real click target for pointer, keyboard and tests. */}
                  <input
                    type="radio"
                    name="comment-visibility"
                    className="absolute inset-0 cursor-pointer opacity-0"
                    checked={visibility === mode}
                    onChange={() => setVisibility(mode)}
                  />
                  {mode === 'internal' ? (
                    <LockIcon aria-hidden="true" className="size-3.5" />
                  ) : (
                    <ReplyIcon aria-hidden="true" className="size-3.5" />
                  )}
                  {mode === 'internal' ? copy.comments.internal : copy.comments.public}
                </label>
              ))}
            </fieldset>
          ) : null}
          <label htmlFor="comment-body" className="block text-sm font-medium">
            {copy.comments.body}
          </label>
          <Textarea
            id="comment-body"
            required
            maxLength={20000}
            value={body}
            onChange={(event) => setBody(event.target.value)}
          />
          <p className="text-xs text-muted-foreground">
            {visibility === 'public' ? copy.comments.publicHint : copy.comments.internalHint}
          </p>
          <AttachmentsField key={uploadCycle} maxFiles={10} onChange={setAttachments} />
          {save.error ? <FormErrorBanner title={copy.settings.failed} error={save.error} /> : null}
          <Button
            type="submit"
            disabled={save.isPending || attachments.uploading || !body.trim()}
            variant={internalMode ? 'outline' : 'default'}
          >
            {internalMode ? <LockIcon aria-hidden="true" /> : <ReplyIcon aria-hidden="true" />}
            {internalMode ? copy.comments.sendInternal : copy.comments.sendPublic}
          </Button>
        </form>
      ) : null}
    </section>
  )
}
