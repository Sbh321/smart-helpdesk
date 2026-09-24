import { useBlocker } from '@tanstack/react-router'
import { CircleDotIcon } from 'lucide-react'
import { useRef } from 'react'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'

export interface SaveBarProps {
  /** The form differs from what is saved (TanStack Form: `!state.isDefaultValue`). */
  dirty: boolean
  submitting: boolean
  saveLabel?: string
  onDiscard: () => void
  /** A form that creates something can be saved clean (to show what is missing); an edit form cannot. */
  saveWhenClean?: boolean
  className?: string
}

/**
 * The save state of a settings form (roadmap M4-12): Save always at the same place; while there are
 * unsaved changes the bar says so in words, offers Discard, and stays in view at the bottom of the
 * screen. Place it last inside the `<form>`, so Save submits it.
 */
export function SaveBar({
  dirty,
  submitting,
  saveLabel = copy.settingsPage.save,
  onDiscard,
  saveWhenClean = false,
  className,
}: SaveBarProps) {
  return (
    <div
      data-dirty={dirty || undefined}
      className={cn(
        'flex flex-wrap items-center gap-3 data-dirty:sticky data-dirty:bottom-0 data-dirty:z-10 data-dirty:rounded-lg data-dirty:border data-dirty:border-border data-dirty:bg-surface data-dirty:p-3 data-dirty:shadow-md',
        className,
      )}
    >
      <Button type="submit" disabled={submitting || (!dirty && !saveWhenClean)}>
        {submitting ? copy.settingsPage.saving : saveLabel}
      </Button>
      {dirty ? (
        <>
          <Button type="button" variant="outline" disabled={submitting} onClick={onDiscard}>
            {copy.settingsPage.discard}
          </Button>
          <p className="flex items-center gap-1.5 text-sm text-muted-foreground" role="status">
            <CircleDotIcon aria-hidden="true" className="size-4 text-warning" />
            {copy.settingsPage.unsaved}
          </p>
        </>
      ) : null}
    </div>
  )
}

/**
 * Asks before leaving a page with unsaved changes (roadmap M4-12): an in-app navigation opens a
 * confirmation (stay, or leave and lose them); closing or reloading the tab gets the browser's own
 * prompt. Mount it next to the form with the same `dirty`.
 */
export function UnsavedChangesGuard({ dirty }: { dirty: boolean }) {
  const blocker = useBlocker({
    shouldBlockFn: () => dirty,
    enableBeforeUnload: () => dirty,
    withResolver: true,
  })
  // ConfirmDialog closes itself after the confirmation; that close must not cancel the navigation.
  const leaving = useRef(false)
  return (
    <ConfirmDialog
      open={blocker.status === 'blocked'}
      onOpenChange={(open) => {
        if (!open && !leaving.current && blocker.status === 'blocked') blocker.reset()
        leaving.current = false
      }}
      destructive
      title={copy.settingsPage.leaveTitle}
      description={copy.settingsPage.leaveBody}
      confirmLabel={copy.settingsPage.leave}
      onConfirm={async () => {
        leaving.current = true
        if (blocker.status === 'blocked') blocker.proceed()
      }}
    />
  )
}
