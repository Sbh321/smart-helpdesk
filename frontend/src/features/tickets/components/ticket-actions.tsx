import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
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
import { toast } from '@/components/ui/sonner'
import { copy } from '@/copy/en'
import { AssignmentDialog } from '@/features/automation'
import { hasProblemCode, problemMessage } from '@/lib/api/problem-messages'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { serverFieldErrors } from '@/lib/forms/messages'
import { type Ticket, transitionTicket } from '../api/ticket-queries'
import { PriorityOverrideDialog } from './priority-override-dialog'
import { EditTicketForm } from './ticket-edit-form'

const text = copy.tickets.detail

function transitionLabel(ticket: Ticket, target: TicketStatus): string {
  if (target === 'in_progress') {
    if (ticket.status === 'pending') return text.resume
    if (ticket.status === 'resolved' || ticket.status === 'closed') return text.reopen
  }
  return text.transitions[target]
}

/** Server supplies the state-machine targets; permissions also keep mock/offline UI honest. */
export function TicketActions({ ticket }: { ticket: Ticket }) {
  const { session, permissions } = useSession()
  const canEdit = useCan('tickets.update')
  const canAssign = useCan('tickets.assign')
  const tenantId = session?.tenant.id ?? ''
  const client = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [priorityOpen, setPriorityOpen] = useState(false)
  const [assignmentOpen, setAssignmentOpen] = useState(false)
  const [confirm, setConfirm] = useState<TicketStatus | null>(null)
  const [comment, setComment] = useState('')
  const [commentErrors, setCommentErrors] = useState<string[]>([])
  const [failure, setFailure] = useState<unknown>(null)
  const submitting = useRef(false)
  const key = queryKeys.tickets.detail(tenantId, ticket.id)
  const change = useMutation({
    mutationFn: (input: { status: TicketStatus; comment?: string }) => transitionTicket(ticket.id, input),
    onMutate: async (input) => {
      setFailure(null)
      await client.cancelQueries({ queryKey: key })
      const previous = client.getQueryData<Ticket>(key)
      if (previous) client.setQueryData(key, { ...previous, status: input.status, allowed_transitions: [] })
      return { previous }
    },
    onError: (error, _input, context) => {
      if (context?.previous) client.setQueryData(key, context.previous)
      // `resolution_comment_required` names its input in `meta.field`; it belongs on the field, not in a banner.
      if (hasProblemCode(error, 'resolution_comment_required')) {
        setCommentErrors([problemMessage(error) ?? text.resolutionRequired])
        setConfirm('resolved')
        return
      }
      setCommentErrors(serverFieldErrors(error).comment ?? [])
      setFailure(error)
    },
    onSuccess: (saved) => {
      client.setQueryData(key, saved)
      setConfirm(null)
      setComment('')
      toast.success(text.statusChanged)
    },
    onSettled: async () => {
      // Pending pauses the SLA timers and resolving meets them: the SLA panel reads a separate query.
      await Promise.all([
        client.invalidateQueries({ queryKey: queryKeys.tickets.all(tenantId) }),
        client.invalidateQueries({ queryKey: queryKeys.sla.ticket(tenantId, ticket.id) }),
      ])
      submitting.current = false
    },
  })

  useEffect(() => {
    if (!canEdit) return
    const shortcut = (event: KeyboardEvent) => {
      if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || event.repeat) return
      if (
        event.target instanceof Element &&
        event.target.closest(
          'input, textarea, select, button, a, [contenteditable="true"], [role="dialog"], [role="combobox"]',
        )
      )
        return
      if (event.key === 'e' && !change.isPending && !confirm) {
        event.preventDefault()
        setEditing(true)
      }
      if (event.key === 'a' && canAssign && !change.isPending && !confirm) {
        event.preventDefault()
        setAssignmentOpen(true)
      }
    }
    window.addEventListener('keydown', shortcut)
    return () => window.removeEventListener('keydown', shortcut)
  }, [canAssign, canEdit, change.isPending, confirm])

  function submit(status: TicketStatus) {
    if (submitting.current) return
    if (status === 'resolved' && !comment.trim()) {
      setCommentErrors([text.resolutionRequired])
      return
    }
    submitting.current = true
    change.mutate({ status, ...(comment.trim() ? { comment: comment.trim() } : {}) })
  }

  if (!canEdit) return null
  const targets = (ticket.allowed_transitions ?? []).filter((target) => {
    const permission =
      target === 'resolved'
        ? 'tickets.resolve'
        : target === 'closed'
          ? 'tickets.close'
          : ticket.status === 'closed' || ticket.status === 'resolved'
            ? 'tickets.reopen'
            : null
    return !permission || permissions.includes(permission)
  })
  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-2" aria-busy={change.isPending}>
        <Button
          variant="outline"
          onClick={() => setEditing(true)}
          disabled={change.isPending}
          aria-keyshortcuts="e"
        >
          {text.edit}
        </Button>
        <Button variant="outline" onClick={() => setPriorityOpen(true)}>
          {copy.priority.overrideTitle}
        </Button>
        {canAssign ? (
          <Button variant="outline" aria-keyshortcuts="a" onClick={() => setAssignmentOpen(true)}>
            {copy.assignment.title}
          </Button>
        ) : null}
        {targets.map((target) => (
          <Button
            key={target}
            variant="outline"
            disabled={change.isPending}
            onClick={() => {
              setCommentErrors([])
              setFailure(null)
              if (target === 'resolved' || target === 'closed') setConfirm(target)
              else submit(target)
            }}
          >
            {transitionLabel(ticket, target)}
          </Button>
        ))}
      </div>
      {failure && !confirm ? <FormErrorBanner title={text.failed} error={failure} /> : null}
      {ticket.status === 'pending' ? (
        <p className="text-sm text-muted-foreground">{text.pendingWarning}</p>
      ) : null}
      <Dialog
        open={confirm !== null}
        onOpenChange={(open) => {
          if (!open && !change.isPending) {
            setConfirm(null)
            setComment('')
            setFailure(null)
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{confirm ? text.transitions[confirm] : text.confirm}</DialogTitle>
            <DialogDescription>{text.transitionDescription}</DialogDescription>
          </DialogHeader>
          {failure ? <FormErrorBanner title={text.failed} error={failure} /> : null}
          {confirm === 'resolved' ? (
            <TextareaField
              id="resolution-comment"
              label={text.resolutionComment}
              value={comment}
              onValueChange={(value) => {
                setComment(value)
                setCommentErrors([])
              }}
              errors={commentErrors}
              disabled={change.isPending}
            />
          ) : null}
          <DialogFooter>
            <Button
              variant="outline"
              disabled={change.isPending}
              onClick={() => {
                setConfirm(null)
                setComment('')
                setFailure(null)
              }}
            >
              {copy.tickets.create.cancel}
            </Button>
            <Button
              disabled={change.isPending}
              onClick={() => {
                if (confirm) submit(confirm)
              }}
            >
              {change.isPending ? text.changing : text.confirm}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Dialog open={editing} onOpenChange={setEditing}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>{text.edit}</DialogTitle>
            <DialogDescription>{text.editDescription}</DialogDescription>
          </DialogHeader>
          {editing ? <EditTicketForm ticket={ticket} onSaved={() => setEditing(false)} /> : null}
        </DialogContent>
      </Dialog>
      <PriorityOverrideDialog ticket={ticket} open={priorityOpen} onOpenChange={setPriorityOpen} />
      {canAssign ? (
        <AssignmentDialog ticket={ticket} open={assignmentOpen} onOpenChange={setAssignmentOpen} />
      ) : null}
    </div>
  )
}
