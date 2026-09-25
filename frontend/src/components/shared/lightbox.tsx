import type { LucideIcon } from 'lucide-react'
import {
  ChevronLeftIcon,
  ChevronRightIcon,
  DownloadIcon,
  ExternalLinkIcon,
  FileIcon,
  InfoIcon,
  LoaderCircleIcon,
  RotateCwIcon,
  XIcon,
  ZoomInIcon,
  ZoomOutIcon,
} from 'lucide-react'
import { type PointerEvent, type ReactNode, useEffect, useRef, useState, type WheelEvent } from 'react'
import { Button, buttonVariants } from '@/components/ui/button'
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import { cn } from '@/lib/utils'

/** How the lightbox shows a file: an image on the zoomable stage, a document in a frame, or a file card. */
export type LightboxKind = 'image' | 'pdf' | 'text' | 'file'

/**
 * One file as the lightbox shows it. The lightbox knows nothing about the API: callers (the media
 * feature's adapters) build these with ready-made URLs and labels.
 */
export interface LightboxItem {
  id: string
  name: string
  kind: LightboxKind
  /** One line under the name, for example "PNG image · 2.4 MB · 1920 × 1080". */
  summary: string
  /** The type's icon on the file card and in the thumbnail strip. */
  icon?: LucideIcon
  thumbUrl?: string
  /** A lighter rendition shown first; the original replaces it when the image is zoomed in. */
  previewUrl?: string
  /** The original: the full-resolution image, or the document the frame shows. */
  sourceUrl?: string
  /** Opens the original in a new tab. */
  openUrl?: string
  downloadUrl?: string
  /** Rows of the details panel. */
  details?: { label: string; value: ReactNode }[]
}

export interface LightboxProps {
  items: LightboxItem[]
  /** The shown item; `null` closes the lightbox. */
  index: number | null
  onIndexChange: (index: number) => void
  onClose: () => void
}

const MIN_ZOOM = 0.5
const MAX_ZOOM = 8
const ZOOM_STEP = 1.5
/** Beyond this the preview rendition looks soft, so the original is loaded instead. */
const ORIGINAL_FROM_ZOOM = 1.5
const SWIPE_DISTANCE = 60

const text = copy.lightbox
const clampZoom = (value: number) => Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, value))

/**
 * The one place files are looked at (docs/06-design-system/components.md §Lightbox): a full-screen dialog
 * on a dark scrim in both themes, with the file's name and summary, zoom, rotate, a details panel,
 * "open in a new tab" and download, previous and next (buttons, arrow keys, swipe) and a thumbnail strip
 * when there is more than one file. Images zoom with the buttons, the wheel, double-click and + − 0, and
 * pan by dragging; PDF and text show in a frame; any other type gets a file card with its actions.
 */
export function Lightbox({ items, index, onIndexChange, onClose }: LightboxProps) {
  const item = index === null ? undefined : items[index]
  const total = items.length
  const [zoom, setZoom] = useState(1)
  const [rotation, setRotation] = useState(0)
  const [offset, setOffset] = useState({ x: 0, y: 0 })
  const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>('loading')
  const [showInfo, setShowInfo] = useState(false)
  const drag = useRef<{ x: number; y: number; startX: number; startY: number; moved: boolean } | null>(null)
  const keyHandler = useRef<(event: KeyboardEvent) => void>(() => undefined)
  const open = index !== null

  // Shortcuts listen on the window while open: a button that becomes disabled (Next on the last file)
  // drops focus to the page, and the keys must keep working all the same.
  useEffect(() => {
    if (!open) return
    const listener = (event: KeyboardEvent) => keyHandler.current(event)
    // Capture phase: a focused control inside (a tooltip trigger) must not swallow the shortcut.
    window.addEventListener('keydown', listener, true)
    return () => window.removeEventListener('keydown', listener, true)
  }, [open])
  const strip = useRef<HTMLUListElement>(null)
  const itemId = item?.id

  // Each file starts fitted, upright and centred.
  useEffect(() => {
    if (itemId === undefined) return
    setZoom(1)
    setRotation(0)
    setOffset({ x: 0, y: 0 })
    setStatus('loading')
    strip.current
      ?.querySelector('[aria-current="true"]')
      ?.scrollIntoView({ block: 'nearest', inline: 'center' })
  }, [itemId])

  // The neighbours' images load in the background, so previous and next show at once.
  useEffect(() => {
    if (index === null) return
    for (const neighbour of [items[index - 1], items[index + 1]]) {
      const url = neighbour?.kind === 'image' ? (neighbour.previewUrl ?? neighbour.sourceUrl) : undefined
      if (url) new Image().src = url
    }
  }, [index, items])

  if (!item || index === null) {
    return <Dialog open={false} />
  }

  const canPrevious = index > 0
  const canNext = index < total - 1
  const isImage = item.kind === 'image'
  const imageUrl =
    zoom >= ORIGINAL_FROM_ZOOM || !item.previewUrl ? (item.sourceUrl ?? item.previewUrl) : item.previewUrl

  const zoomTo = (value: number) => {
    const next = clampZoom(value)
    setZoom(next)
    if (next <= 1) setOffset({ x: 0, y: 0 })
  }
  const go = (next: number) => {
    if (next >= 0 && next < total && next !== index) onIndexChange(next)
  }

  keyHandler.current = (event: KeyboardEvent) => {
    if (event.altKey || event.ctrlKey || event.metaKey) return
    const actions: Record<string, () => void> = {
      ArrowLeft: () => go(index - 1),
      ArrowRight: () => go(index + 1),
      Home: () => go(0),
      End: () => go(total - 1),
      ...(isImage
        ? {
            '+': () => zoomTo(zoom * ZOOM_STEP),
            '=': () => zoomTo(zoom * ZOOM_STEP),
            '-': () => zoomTo(zoom / ZOOM_STEP),
            '0': () => zoomTo(1),
            r: () => setRotation((value) => (value + 90) % 360),
          }
        : {}),
      i: () => setShowInfo((value) => !value),
    }
    const action = actions[event.key]
    if (!action) return
    event.preventDefault()
    action()
  }

  const onWheel = (event: WheelEvent) => {
    if (!isImage || status !== 'ready') return
    zoomTo(zoom * (event.deltaY < 0 ? 1.15 : 1 / 1.15))
  }

  const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
    if (event.button !== 0 || (event.target as HTMLElement).closest('button, a')) return
    drag.current = { x: offset.x, y: offset.y, startX: event.clientX, startY: event.clientY, moved: false }
    event.currentTarget.setPointerCapture(event.pointerId)
  }
  const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
    const start = drag.current
    if (!start) return
    const dx = event.clientX - start.startX
    const dy = event.clientY - start.startY
    if (Math.abs(dx) + Math.abs(dy) > 4) start.moved = true
    if (isImage && zoom > 1) setOffset({ x: start.x + dx, y: start.y + dy })
  }
  const onPointerUp = (event: PointerEvent<HTMLDivElement>) => {
    const start = drag.current
    drag.current = null
    if (!start || zoom > 1) return
    // Fitted, a horizontal swipe moves to the neighbour.
    const dx = event.clientX - start.startX
    const dy = event.clientY - start.startY
    if (Math.abs(dx) > SWIPE_DISTANCE && Math.abs(dy) < SWIPE_DISTANCE) go(dx < 0 ? index + 1 : index - 1)
  }

  const Icon = item.icon ?? FileIcon
  const zoomPercent = `${Math.round(zoom * 100)}%`

  return (
    <Dialog open onOpenChange={(open) => (open ? undefined : onClose())}>
      <DialogContent
        showCloseButton={false}
        data-theme="dark"
        className="top-0 left-0 flex h-dvh w-screen max-w-none translate-x-0 translate-y-0 flex-col gap-0 rounded-none bg-scrim/95 p-0 text-foreground ring-0 sm:max-w-none"
      >
        <header className="flex items-center gap-2 border-b border-border px-3 py-2">
          <div className="min-w-0 flex-1">
            <DialogTitle className="truncate font-medium text-base">{item.name}</DialogTitle>
            <DialogDescription className="truncate text-muted-foreground text-xs">
              {item.summary}
            </DialogDescription>
          </div>
          {total > 1 ? (
            <span className="hidden shrink-0 text-muted-foreground text-sm tabular-nums sm:inline">
              {fill(text.counter, { current: index + 1, total })}
            </span>
          ) : null}
          <div role="toolbar" aria-label={text.toolbar} className="flex shrink-0 items-center gap-0.5">
            {isImage ? (
              <>
                <ToolButton
                  label={text.zoomOut}
                  disabled={zoom <= MIN_ZOOM}
                  onClick={() => zoomTo(zoom / ZOOM_STEP)}
                >
                  <ZoomOutIcon aria-hidden="true" />
                </ToolButton>
                <Hint label={text.fit}>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="hidden w-14 tabular-nums sm:inline-flex"
                    aria-label={fill(text.fitAt, { zoom: zoomPercent })}
                    onClick={() => zoomTo(1)}
                  >
                    {zoomPercent}
                  </Button>
                </Hint>
                <ToolButton
                  label={text.zoomIn}
                  disabled={zoom >= MAX_ZOOM}
                  onClick={() => zoomTo(zoom * ZOOM_STEP)}
                >
                  <ZoomInIcon aria-hidden="true" />
                </ToolButton>
                <ToolButton label={text.rotate} onClick={() => setRotation((value) => (value + 90) % 360)}>
                  <RotateCwIcon aria-hidden="true" />
                </ToolButton>
              </>
            ) : null}
            {item.details && item.details.length > 0 ? (
              <ToolButton
                label={showInfo ? text.hideDetails : text.showDetails}
                pressed={showInfo}
                onClick={() => setShowInfo((value) => !value)}
              >
                <InfoIcon aria-hidden="true" />
              </ToolButton>
            ) : null}
            {item.openUrl ? (
              <ToolLink label={text.openInNewTab} href={item.openUrl} newTab>
                <ExternalLinkIcon aria-hidden="true" />
              </ToolLink>
            ) : null}
            {item.downloadUrl ? (
              <ToolLink label={text.download} href={item.downloadUrl} download={item.name}>
                <DownloadIcon aria-hidden="true" />
              </ToolLink>
            ) : null}
            <Hint label={text.close}>
              <DialogClose
                render={<Button type="button" variant="ghost" size="icon-sm" aria-label={text.close} />}
              >
                <XIcon aria-hidden="true" />
              </DialogClose>
            </Hint>
          </div>
        </header>

        <div className="relative flex min-h-0 flex-1">
          {/* The stage: pointer drag pans a zoomed image and swipes between files. */}
          <div
            data-slot="lightbox-stage"
            className={cn(
              'relative flex min-w-0 flex-1 touch-none items-center justify-center overflow-hidden p-4 select-none sm:px-16',
              isImage && zoom > 1 && 'cursor-grab active:cursor-grabbing',
            )}
            onWheel={onWheel}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerUp}
            onPointerCancel={() => {
              drag.current = null
            }}
          >
            {isImage && imageUrl && status !== 'failed' ? (
              <img
                key={item.id}
                src={imageUrl}
                alt={item.name}
                draggable={false}
                onLoad={() => setStatus('ready')}
                onError={() => setStatus('failed')}
                onDoubleClick={() => zoomTo(zoom > 1 ? 1 : 2)}
                className="max-h-full max-w-full object-contain transition-transform duration-150 motion-reduce:transition-none"
                style={{
                  transform: `translate(${offset.x}px, ${offset.y}px) scale(${zoom}) rotate(${rotation}deg)`,
                }}
              />
            ) : null}
            {(item.kind === 'pdf' || item.kind === 'text') && item.sourceUrl ? (
              // A document shows on paper white in both themes, as it would when printed.
              <div className="size-full max-w-5xl overflow-hidden rounded-card bg-paper shadow-3">
                <iframe
                  key={item.id}
                  src={item.sourceUrl}
                  title={item.name}
                  // Without its own scheme the frame is transparent and a plain-text page, following the
                  // dark scrim, draws white text on this white paper. With it, the browser paints the frame
                  // opaque in the viewer's scheme, so the text reads in both themes.
                  className="size-full scheme-light"
                  onLoad={() => setStatus('ready')}
                />
              </div>
            ) : null}
            {item.kind === 'file' || status === 'failed' || (!imageUrl && isImage) ? (
              <FileCard item={item} icon={Icon} failed={status === 'failed'} />
            ) : null}
            {isImage && status === 'loading' ? (
              <p
                className="pointer-events-none absolute inset-0 flex items-center justify-center"
                role="status"
              >
                <LoaderCircleIcon
                  aria-hidden="true"
                  className="size-8 text-muted-foreground motion-safe:animate-spin"
                />
                <span className="sr-only">{fill(text.loading, { name: item.name })}</span>
              </p>
            ) : null}

            {total > 1 ? (
              <>
                <StageArrow
                  label={text.previous}
                  side="left"
                  disabled={!canPrevious}
                  onClick={() => go(index - 1)}
                >
                  <ChevronLeftIcon aria-hidden="true" />
                </StageArrow>
                <StageArrow label={text.next} side="right" disabled={!canNext} onClick={() => go(index + 1)}>
                  <ChevronRightIcon aria-hidden="true" />
                </StageArrow>
              </>
            ) : null}
          </div>

          {showInfo && item.details ? (
            <aside
              aria-label={text.details}
              className="absolute inset-y-0 right-0 z-10 w-72 overflow-y-auto border-border border-l bg-popover p-4 lg:static"
            >
              <h2 className="mb-3 font-medium text-sm">{text.details}</h2>
              <dl className="space-y-3 text-sm">
                {item.details.map((row) => (
                  <div key={row.label}>
                    <dt className="text-muted-foreground text-xs">{row.label}</dt>
                    <dd className="mt-0.5 break-words">{row.value}</dd>
                  </div>
                ))}
              </dl>
            </aside>
          ) : null}
        </div>

        {total > 1 ? (
          <ul
            ref={strip}
            aria-label={text.thumbnails}
            className="flex shrink-0 gap-2 overflow-x-auto border-border border-t px-3 py-2"
          >
            {items.map((other, position) => {
              const OtherIcon = other.icon ?? FileIcon
              const current = position === index
              return (
                <li key={other.id} className="shrink-0">
                  <button
                    type="button"
                    aria-label={fill(text.show, { name: other.name })}
                    aria-current={current ? 'true' : undefined}
                    onClick={() => go(position)}
                    className={cn(
                      'flex size-14 items-center justify-center overflow-hidden rounded-control border border-border bg-surface outline-none transition focus-visible:ring-3 focus-visible:ring-ring/50',
                      current ? 'ring-2 ring-primary' : 'opacity-60 hover:opacity-100',
                    )}
                  >
                    {other.thumbUrl ? (
                      <img src={other.thumbUrl} alt="" loading="lazy" className="size-full object-cover" />
                    ) : (
                      <OtherIcon aria-hidden="true" className="size-6 text-muted-foreground" />
                    )}
                  </button>
                </li>
              )
            })}
          </ul>
        ) : null}
        <p className="sr-only" aria-live="polite">
          {fill(text.announce, { current: index + 1, total, name: item.name })}
        </p>
      </DialogContent>
    </Dialog>
  )
}

function ToolButton({
  label,
  onClick,
  disabled,
  pressed,
  children,
}: {
  label: string
  onClick: () => void
  disabled?: boolean
  pressed?: boolean
  children: ReactNode
}) {
  return (
    <Hint label={label}>
      <Button
        type="button"
        variant="ghost"
        size="icon-sm"
        aria-label={label}
        aria-pressed={pressed}
        disabled={disabled}
        onClick={onClick}
      >
        {children}
      </Button>
    </Hint>
  )
}

function ToolLink({
  label,
  href,
  newTab,
  download,
  children,
}: {
  label: string
  href: string
  newTab?: boolean
  download?: string
  children: ReactNode
}) {
  return (
    <Hint label={label}>
      <a
        href={href}
        aria-label={label}
        download={download}
        {...(newTab ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        className={buttonVariants({ variant: 'ghost', size: 'icon-sm' })}
      >
        {children}
      </a>
    </Hint>
  )
}

function StageArrow({
  label,
  side,
  disabled,
  onClick,
  children,
}: {
  label: string
  side: 'left' | 'right'
  disabled: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <Hint label={label} side={side === 'left' ? 'right' : 'left'}>
      <Button
        type="button"
        variant="outline"
        size="icon"
        aria-label={label}
        disabled={disabled}
        onClick={onClick}
        className={cn(
          'absolute top-1/2 z-10 -translate-y-1/2 rounded-full shadow-2 disabled:opacity-0',
          side === 'left' ? 'left-2 sm:left-4' : 'right-2 sm:right-4',
        )}
      >
        {children}
      </Button>
    </Hint>
  )
}

function FileCard({ item, icon: Icon, failed }: { item: LightboxItem; icon: LucideIcon; failed: boolean }) {
  return (
    <div className="flex max-w-sm flex-col items-center gap-3 rounded-card border border-border bg-surface p-8 text-center shadow-2">
      <Icon aria-hidden="true" className="size-14 text-muted-foreground" />
      <div className="space-y-1">
        <p className="break-all font-medium">{item.name}</p>
        <p className="text-muted-foreground text-xs">{item.summary}</p>
      </div>
      <p className="text-muted-foreground text-sm">{failed ? text.failed : text.noPreview}</p>
      <div className="flex flex-wrap justify-center gap-2">
        {item.downloadUrl ? (
          <a href={item.downloadUrl} download={item.name} className={buttonVariants({ size: 'sm' })}>
            <DownloadIcon aria-hidden="true" />
            {text.download}
          </a>
        ) : null}
        {item.openUrl ? (
          <a
            href={item.openUrl}
            target="_blank"
            rel="noopener noreferrer"
            className={buttonVariants({ variant: 'outline', size: 'sm' })}
          >
            <ExternalLinkIcon aria-hidden="true" />
            {text.openInNewTab}
          </a>
        ) : null}
      </div>
    </div>
  )
}
