import { UploadCloudIcon } from 'lucide-react'
import { type DragEvent, useRef, useState } from 'react'
import { cn } from '@/lib/utils'

const carriesFiles = (event: DragEvent) => Array.from(event.dataTransfer.types).includes('Files')

/**
 * Drag and drop of files onto any element (docs/06-design-system/components.md §File drop): spread
 * `dropProps` on the target and read `dragging` to show it is ready. Only drags that carry files count,
 * so dragging text or a link over the page changes nothing. Nested children do not flicker the state:
 * enter and leave are counted.
 */
export function useFileDrop(onFiles: (files: FileList) => void, disabled = false) {
  const [dragging, setDragging] = useState(false)
  const depth = useRef(0)
  const ignore = (event: DragEvent) => disabled || !carriesFiles(event)

  return {
    dragging: dragging && !disabled,
    dropProps: {
      onDragEnter: (event: DragEvent) => {
        if (ignore(event)) return
        event.preventDefault()
        depth.current += 1
        setDragging(true)
      },
      onDragOver: (event: DragEvent) => {
        if (ignore(event)) return
        event.preventDefault()
        event.dataTransfer.dropEffect = 'copy'
      },
      onDragLeave: (event: DragEvent) => {
        if (ignore(event)) return
        depth.current = Math.max(0, depth.current - 1)
        if (depth.current === 0) setDragging(false)
      },
      onDrop: (event: DragEvent) => {
        if (ignore(event)) return
        event.preventDefault()
        depth.current = 0
        setDragging(false)
        if (event.dataTransfer.files.length > 0) onFiles(event.dataTransfer.files)
      },
    },
  }
}

/** The "drop here" layer over a large drop target (the media library). Visual only; the target's own
 *  controls keep the keyboard route. */
export function DropOverlay({
  visible,
  label,
  className,
}: {
  visible: boolean
  label: string
  className?: string
}) {
  if (!visible) return null
  return (
    <div
      aria-hidden="true"
      className={cn(
        'pointer-events-none absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 rounded-card border-2 border-primary border-dashed bg-primary/10 font-medium text-primary text-sm backdrop-blur-[2px]',
        className,
      )}
    >
      <UploadCloudIcon className="size-8" />
      {label}
    </div>
  )
}
