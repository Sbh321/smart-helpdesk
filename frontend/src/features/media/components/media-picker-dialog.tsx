import { useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { copy, fill } from '@/copy/en'
import { useLocalListParams } from '@/lib/list-params'
import { type MediaItem, type MediaTypeGroup, mediaListSchema } from '../api/media-queries'
import { MediaBrowser } from './media-browser'

const text = copy.media

export interface MediaPickerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onPick: (items: MediaItem[]) => void
  /** One file (a logo) instead of several (attachments). */
  single?: boolean
  /** Only this type group can be chosen (images for a logo). */
  type?: MediaTypeGroup
  /** Files already in the form: shown, but not selectable again. */
  disabledIds?: ReadonlySet<string>
}

/**
 * "Choose from library" (docs/03-architecture/frontend.md §Media): the whole library in a large dialog,
 * with its folders, search, filters, sort, grid or table and previews. Filters live in the dialog, not
 * in the address of the page behind it, and start fresh on every opening.
 */
export function MediaPickerDialog(props: MediaPickerDialogProps) {
  const { open, onOpenChange, single, type } = props
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex h-[min(92dvh,56rem)] flex-col gap-4 sm:max-w-6xl">
        <DialogHeader>
          <DialogTitle>{type === 'image' ? text.pickImageTitle : text.pickTitle}</DialogTitle>
          <DialogDescription>
            {type === 'image' ? text.pickImageDescription : text.pickDescription}
          </DialogDescription>
        </DialogHeader>
        {/* Mounted only while open, so each opening starts with no filters and no selection. */}
        {open ? <PickerBody {...props} single={single} /> : null}
      </DialogContent>
    </Dialog>
  )
}

function PickerBody({ onOpenChange, onPick, single = false, type, disabledIds }: MediaPickerDialogProps) {
  const list = useLocalListParams(mediaListSchema, type ? { type } : {})
  const [selected, setSelected] = useState<MediaItem[]>([])

  const use = () => {
    onPick(selected)
    onOpenChange(false)
  }

  return (
    <>
      <div className="-mx-4 min-h-0 flex-1 overflow-y-auto px-4">
        <MediaBrowser
          list={list}
          mode="pick"
          selected={selected}
          onSelectedChange={setSelected}
          single={single}
          lockedType={type}
          disabledIds={disabledIds}
          viewKey="sh.media.pickerView"
        />
      </div>
      <DialogFooter className="items-center sm:justify-between">
        <p className="text-muted-foreground text-sm tabular-nums" aria-live="polite">
          {selected.length === 0
            ? text.nothingSelected
            : fill(text.selectedCount, { count: selected.length })}
        </p>
        <div className="flex flex-col-reverse gap-2 sm:flex-row">
          <DialogClose render={<Button type="button" variant="outline" />}>{text.cancel}</DialogClose>
          <Button type="button" disabled={selected.length === 0} onClick={use}>
            {selected.length > 1 ? fill(text.useSelected, { count: selected.length }) : text.useOne}
          </Button>
        </div>
      </DialogFooter>
    </>
  )
}
