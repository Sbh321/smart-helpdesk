import { useRef, useState } from 'react'
import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'
import { FormErrorBanner } from './form-error-banner'

export interface ConfirmDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description: string
  confirmLabel: string
  /** Destructive actions get the destructive button and the initial focus on Cancel. */
  destructive?: boolean
  /** Title of the banner when `onConfirm` rejects; the dialog stays open so the user can retry. */
  failedTitle?: string
  onConfirm: () => Promise<unknown>
}

/**
 * The question before an action that cannot be undone (docs/06-design-system/components.md
 * §ConfirmDialog). On Base UI `AlertDialog`: it does not close on an outside press, and while the
 * action runs both buttons are disabled.
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  destructive = false,
  failedTitle,
  onConfirm,
}: ConfirmDialogProps) {
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<unknown>(null)
  const cancel = useRef<HTMLButtonElement>(null)

  async function confirm() {
    setBusy(true)
    setFailure(null)
    try {
      await onConfirm()
      onOpenChange(false)
    } catch (error) {
      setFailure(error)
    } finally {
      setBusy(false)
    }
  }

  return (
    <AlertDialog
      open={open}
      onOpenChange={(next) => {
        if (busy) return
        if (!next) setFailure(null)
        onOpenChange(next)
      }}
    >
      <AlertDialogContent initialFocus={destructive ? cancel : undefined}>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>{description}</AlertDialogDescription>
        </AlertDialogHeader>
        {failure ? <FormErrorBanner title={failedTitle ?? copy.confirm.failed} error={failure} /> : null}
        <AlertDialogFooter>
          <Button
            ref={cancel}
            type="button"
            variant="outline"
            disabled={busy}
            onClick={() => onOpenChange(false)}
          >
            {copy.confirm.cancel}
          </Button>
          <Button
            type="button"
            variant={destructive ? 'destructive' : 'default'}
            disabled={busy}
            aria-busy={busy}
            onClick={() => void confirm()}
          >
            {confirmLabel}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
