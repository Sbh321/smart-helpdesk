import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { copy } from '@/copy/en'
import { AttachmentsField, type AttachmentUploaderState } from '@/features/media'
import { apiUrl } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
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
            {entries.map((comment) => (
              <li
                key={comment.id}
                className={`rounded-lg border p-3 text-sm ${comment.visibility === 'internal' ? 'border-warning/40 bg-warning/5' : 'border-border'}`}
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="font-medium">
                    {comment.visibility === 'internal' ? copy.comments.internal : copy.comments.public}
                  </span>
                  <time className="text-muted-foreground" dateTime={comment.created_at ?? undefined}>
                    {comment.created_at ? formatInZone(comment.created_at, timeZone) : ''}
                  </time>
                </div>
                <SafeCommentMarkdown body={comment.body} />
                {comment.attachments.length > 0 ? (
                  <ul className="mt-3 flex flex-wrap gap-2">
                    {comment.attachments.map((item) => (
                      <li key={item.id}>
                        <a className="text-primary underline" href={apiUrl(`/v1/media/${item.id}/download`)}>
                          {item.name}
                        </a>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </li>
            ))}
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
          className="space-y-3 rounded-lg border p-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (body.trim() && !attachments.uploading) save.mutate()
          }}
        >
          <fieldset className="flex gap-3">
            <legend className="sr-only">{copy.comments.visibility}</legend>
            <label className="flex items-center gap-1 text-sm">
              <input
                type="radio"
                name="comment-visibility"
                checked={visibility === 'public'}
                onChange={() => setVisibility('public')}
              />
              {copy.comments.public}
            </label>
            {canInternal ? (
              <label className="flex items-center gap-1 text-sm">
                <input
                  type="radio"
                  name="comment-visibility"
                  checked={visibility === 'internal'}
                  onChange={() => setVisibility('internal')}
                />
                {copy.comments.internal}
              </label>
            ) : null}
          </fieldset>
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
          <Button type="submit" disabled={save.isPending || attachments.uploading || !body.trim()}>
            {copy.comments.send}
          </Button>
        </form>
      ) : null}
    </section>
  )
}
