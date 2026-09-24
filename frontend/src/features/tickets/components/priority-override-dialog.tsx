import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SelectField } from '@/components/shared/select-field'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { toast } from '@/components/ui/sonner'
import { copy } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useSession } from '@/lib/auth'
import { overrideTicketPriority, type Ticket } from '../api/ticket-queries'

export function PriorityOverrideDialog({
  ticket,
  open,
  onOpenChange,
}: {
  ticket: Ticket
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const client = useQueryClient()
  const [level, setLevel] = useState(ticket.priority_overridden ? ticket.priority_level : 'auto')
  const [reason, setReason] = useState(ticket.priority_override_reason ?? '')
  const save = useMutation({
    mutationFn: () =>
      overrideTicketPriority(ticket.id, {
        level: level === 'auto' ? null : (level as 'P1' | 'P2' | 'P3' | 'P4'),
        reason: level === 'auto' ? null : reason.trim(),
      }),
    onSuccess: (updated) => {
      client.setQueryData(queryKeys.tickets.detail(tenantId, ticket.id), updated)
      void client.invalidateQueries({ queryKey: queryKeys.tickets.all(tenantId) })
      void client.invalidateQueries({ queryKey: queryKeys.sla.ticket(tenantId, ticket.id) })
      toast.success(copy.priority.overrideSaved)
      onOpenChange(false)
    },
  })
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{copy.priority.overrideTitle}</DialogTitle>
          <DialogDescription>{copy.priority.overrideDescription}</DialogDescription>
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate()
          }}
        >
          <SelectField
            id="priority-override-level"
            label={copy.priority.level}
            value={level}
            onValueChange={setLevel}
            options={[
              { value: 'auto', label: copy.priority.automatic },
              ...(['P1', 'P2', 'P3', 'P4'] as const).map((value) => ({ value, label: value })),
            ]}
          />
          {level !== 'auto' ? (
            <label htmlFor="priority-override-reason" className="grid gap-1 text-sm">
              {copy.priority.reason}
              <Input
                id="priority-override-reason"
                required
                maxLength={255}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
              />
            </label>
          ) : null}
          {save.error ? <FormErrorBanner title={copy.settings.failed} error={save.error} /> : null}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              {copy.sla.cancel}
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {copy.priority.save}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
