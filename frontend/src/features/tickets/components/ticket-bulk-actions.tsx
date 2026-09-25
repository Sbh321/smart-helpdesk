import { useMutation, useQueryClient } from '@tanstack/react-query'
import { UserPlusIcon, WorkflowIcon } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SelectField } from '@/components/shared/select-field'
import type { TicketStatus } from '@/components/shared/status-badge'
import { TextareaField } from '@/components/shared/textarea-field'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { copy, fill } from '@/copy/en'
import { bulkRowMessage } from '@/lib/api/problem-messages'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { serverFieldErrors } from '@/lib/forms/messages'
import {
  type BulkAssignInput,
  type BulkResult,
  bulkAssign,
  bulkTransition,
  runInChunks,
} from '../api/bulk-actions'

const text = copy.tickets.bulk

/** Targets of "Change status"; `open` and `assigned` follow from assignment, not from a status change. */
const STATUS_TARGETS = [
  'in_progress',
  'pending',
  'resolved',
  'closed',
] as const satisfies readonly TicketStatus[]
const TARGET_PERMISSION: Partial<Record<TicketStatus, string>> = {
  resolved: 'tickets.resolve',
  closed: 'tickets.close',
}
const KEEP = 'keep'
const AUTO = 'auto'

export interface TicketLabel {
  number: number
  title: string
}

export type BulkMode = 'status' | 'assign' | null

/** The buttons of the DataTable's bulk bar; the dialogs live outside it (`TicketBulkDialogs`). */
export function TicketBulkButtons({ onOpen }: { onOpen: (mode: Exclude<BulkMode, null>) => void }) {
  const canUpdate = useCan('tickets.update')
  const canAssign = useCan('tickets.assign')
  return (
    <>
      {canUpdate ? (
        <Button type="button" variant="outline" size="sm" onClick={() => onOpen('status')}>
          <WorkflowIcon aria-hidden="true" />
          {text.changeStatus}
        </Button>
      ) : null}
      {canAssign ? (
        <Button type="button" variant="outline" size="sm" onClick={() => onOpen('assign')}>
          <UserPlusIcon aria-hidden="true" />
          {text.assign}
        </Button>
      ) : null}
    </>
  )
}

export interface TicketBulkDialogsProps {
  mode: BulkMode
  onModeChange: (mode: BulkMode) => void
  ids: string[]
  /** Number and title of the tickets the list has shown, to name a failed row. */
  lookup: (id: string) => TicketLabel | undefined
  agents: readonly { id: string; name: string }[]
  teams: readonly { id: string; name: string }[]
  /** Replaces the selection (empty after a run; the failed ids on "Select failed"). */
  onSelectionChange: (ids: string[]) => void
}

type Job = (ticketIds: string[]) => ReturnType<typeof bulkTransition>

/**
 * The bulk dialogs of the ticket list (roadmap M2-11): "Change status" (`tickets.update`) and "Assign"
 * (`tickets.assign`). They stay mounted outside the bulk bar, which disappears with the selection, so the
 * results dialog survives the run. A selection of more than 100 ids goes out as several requests; the
 * results dialog lists every ticket that was not changed, with our wording for its problem code.
 */
export function TicketBulkDialogs({
  mode,
  onModeChange,
  ids,
  lookup,
  agents,
  teams,
  onSelectionChange,
}: TicketBulkDialogsProps) {
  const { session, permissions } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const client = useQueryClient()
  const setMode = onModeChange
  const [status, setStatus] = useState<TicketStatus | null>(null)
  const [comment, setComment] = useState('')
  const [agent, setAgent] = useState<string>(KEEP)
  const [team, setTeam] = useState<string>(KEEP)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null)
  const [result, setResult] = useState<BulkResult | null>(null)

  const run = useMutation({
    mutationFn: (job: Job) => runInChunks(ids, job, (done, total) => setProgress({ done, total })),
    onMutate: () => setErrors({}),
    onSuccess: (answer) => {
      close()
      setResult(answer)
      onSelectionChange([])
    },
    onError: (error) => setErrors(serverFieldErrors(error)),
    onSettled: async () => {
      setProgress(null)
      await client.invalidateQueries({ queryKey: queryKeys.tickets.all(tenantId) })
    },
  })

  function close() {
    setMode(null)
    setStatus(null)
    setComment('')
    setAgent(KEEP)
    setTeam(KEEP)
    setErrors({})
    run.reset()
  }

  function submitStatus(event: FormEvent) {
    event.preventDefault()
    if (run.isPending) return
    if (status === null) return setErrors({ status: [text.statusRequired] })
    if (status === 'resolved' && !comment.trim()) return setErrors({ comment: [text.commentRequired] })
    const note = comment.trim()
    run.mutate((ticketIds) =>
      bulkTransition({ ticket_ids: ticketIds, status, ...(note ? { comment: note } : {}) }),
    )
  }

  function submitAssign(event: FormEvent) {
    event.preventDefault()
    if (run.isPending) return
    if (agent === KEEP && team === KEEP) return setErrors({ agent_id: [text.targetRequired] })
    const target: Omit<BulkAssignInput, 'ticket_ids'> =
      agent === AUTO
        ? { auto: true }
        : { ...(agent !== KEEP ? { agent_id: agent } : {}), ...(team !== KEEP ? { team_id: team } : {}) }
    run.mutate((ticketIds) => bulkAssign({ ticket_ids: ticketIds, ...target }))
  }

  const statusOptions = STATUS_TARGETS.filter((target) => {
    const permission = TARGET_PERMISSION[target]
    return !permission || permissions.includes(permission)
  }).map((target) => ({ value: target, label: copy.tickets.status[target] }))
  const agentOptions = [
    { value: KEEP, label: text.keepAgent },
    { value: AUTO, label: text.autoOption },
    ...agents.map((row) => ({ value: row.id, label: row.name })),
  ]
  const teamOptions = [
    { value: KEEP, label: text.keepTeam },
    ...teams.map((row) => ({ value: row.id, label: row.name })),
  ]
  const count = ids.length
  const failure = run.error && Object.keys(serverFieldErrors(run.error)).length === 0 ? run.error : null
  const failed = result?.rows.filter((row) => !row.ok) ?? []

  const footer = (
    <DialogFooter>
      <Button type="button" variant="outline" disabled={run.isPending} onClick={close}>
        {text.cancel}
      </Button>
      <Button type="submit" disabled={run.isPending}>
        {fill(text.submit, { count })}
      </Button>
    </DialogFooter>
  )
  const progressBar = progress ? (
    <div className="flex flex-col gap-1">
      <div
        role="progressbar"
        aria-label={text.progress}
        aria-valuemin={0}
        aria-valuemax={progress.total}
        aria-valuenow={progress.done}
        className="h-2 overflow-hidden rounded-full bg-muted"
      >
        <div
          className="h-full bg-primary transition-[width]"
          style={{ width: `${progress.total > 0 ? (progress.done / progress.total) * 100 : 0}%` }}
        />
      </div>
      <p className="text-sm text-muted-foreground" aria-live="polite">
        {fill(text.running, { done: progress.done, total: progress.total })}
      </p>
    </div>
  ) : null

  return (
    <>
      <Dialog
        open={mode === 'status'}
        onOpenChange={(open) => {
          if (!open && !run.isPending) close()
        }}
      >
        <DialogContent>
          <form className="flex flex-col gap-4" onSubmit={submitStatus} noValidate>
            <DialogHeader>
              <DialogTitle>{fill(text.statusTitle, { count })}</DialogTitle>
              <DialogDescription>{text.statusDescription}</DialogDescription>
            </DialogHeader>
            {failure ? <FormErrorBanner title={text.failed} error={failure} /> : null}
            <SelectField
              id="bulk-status"
              label={text.statusLabel}
              placeholder={text.statusPlaceholder}
              value={status}
              options={statusOptions}
              onValueChange={(value) => {
                setStatus(value)
                setErrors({})
              }}
              errors={errors.status ?? []}
              disabled={run.isPending}
            />
            <TextareaField
              id="bulk-comment"
              label={status === 'resolved' ? copy.tickets.detail.resolutionComment : text.commentLabel}
              {...(status === 'resolved' ? {} : { description: text.commentOptional })}
              value={comment}
              onValueChange={(value) => {
                setComment(value)
                setErrors({})
              }}
              errors={errors.comment ?? []}
              disabled={run.isPending}
            />
            {progressBar}
            {footer}
          </form>
        </DialogContent>
      </Dialog>

      <Dialog
        open={mode === 'assign'}
        onOpenChange={(open) => {
          if (!open && !run.isPending) close()
        }}
      >
        <DialogContent>
          <form className="flex flex-col gap-4" onSubmit={submitAssign} noValidate>
            <DialogHeader>
              <DialogTitle>{fill(text.assignTitle, { count })}</DialogTitle>
              <DialogDescription>{text.assignDescription}</DialogDescription>
            </DialogHeader>
            {failure ? <FormErrorBanner title={text.failed} error={failure} /> : null}
            <SelectField
              id="bulk-agent"
              label={text.agentLabel}
              value={agent}
              options={agentOptions}
              onValueChange={(value) => {
                setAgent(value)
                if (value === AUTO) setTeam(KEEP)
                setErrors({})
              }}
              errors={[...(errors.agent_id ?? []), ...(errors.auto ?? [])]}
              disabled={run.isPending}
            />
            <SelectField
              id="bulk-team"
              label={text.teamLabel}
              value={team}
              options={teamOptions}
              onValueChange={(value) => {
                setTeam(value)
                setErrors({})
              }}
              errors={errors.team_id ?? []}
              disabled={run.isPending || agent === AUTO}
            />
            {progressBar}
            {footer}
          </form>
        </DialogContent>
      </Dialog>

      <Dialog
        open={result !== null}
        onOpenChange={(open) => {
          if (!open) setResult(null)
        }}
      >
        <DialogContent className="max-h-[90dvh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{text.resultTitle}</DialogTitle>
            <DialogDescription>
              {result ? fill(text.resultSummary, { succeeded: result.succeeded, total: result.total }) : null}
            </DialogDescription>
          </DialogHeader>
          {failed.length === 0 ? (
            <p className="text-sm">{text.resultAllOk}</p>
          ) : (
            <section aria-labelledby="bulk-failures" className="flex flex-col gap-2">
              <h3 id="bulk-failures" className="text-sm font-medium">
                {fill(text.failuresTitle, { count: failed.length })}
              </h3>
              <ul className="flex flex-col gap-1 text-sm">
                {failed.map((row) => {
                  const ticket = lookup(row.ticket_id)
                  const name = ticket
                    ? `${fill(copy.tickets.number, { number: ticket.number })} ${ticket.title}`
                    : fill(text.unknownTicket, { id: row.ticket_id.slice(0, 8) })
                  return (
                    <li key={row.ticket_id} className="rounded-md border border-border px-2 py-1 bg-surface">
                      {fill(text.failureRow, { ticket: name, message: bulkRowMessage(row) })}
                    </li>
                  )
                })}
              </ul>
            </section>
          )}
          <DialogFooter>
            {failed.length > 0 ? (
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  onSelectionChange(failed.map((row) => row.ticket_id))
                  setResult(null)
                }}
              >
                {text.selectFailed}
              </Button>
            ) : null}
            <Button type="button" onClick={() => setResult(null)}>
              {text.done}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
