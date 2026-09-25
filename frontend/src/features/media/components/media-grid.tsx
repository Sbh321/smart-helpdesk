import {
  ArchiveRestoreIcon,
  CheckIcon,
  DownloadIcon,
  EllipsisVerticalIcon,
  ExternalLinkIcon,
  EyeIcon,
  PencilIcon,
  Trash2Icon,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import { formatFileSize } from '@/lib/format/file-size'
import { cn } from '@/lib/utils'
import type { MediaItem } from '../api/media-queries'
import { MEDIA_KIND_ICONS, mediaKind, mediaThumbUrl, mediaTypeLabel, mediaUrls } from '../media-files'
import type { MediaRowActions } from './media-columns'

const text = copy.media

export interface MediaGridProps {
  items: MediaItem[]
  /** `pick`: a card toggles its selection; `manage`: a card opens the preview and has an actions menu. */
  mode: 'manage' | 'pick'
  selectedIds: ReadonlySet<string>
  onToggle?: (item: MediaItem) => void
  onPreview: (item: MediaItem) => void
  actions?: MediaRowActions | null
  /** Ids that cannot be picked again (already attached). */
  disabledIds?: ReadonlySet<string>
}

/** The media library as cards: a thumbnail (or the type's icon), the name, type and size. */
export function MediaGrid({
  items,
  mode,
  selectedIds,
  onToggle,
  onPreview,
  actions,
  disabledIds,
}: MediaGridProps) {
  return (
    <ul aria-label={text.tableLabel} className="grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-3">
      {items.map((item) => (
        <MediaCard
          key={item.id}
          item={item}
          mode={mode}
          selected={selectedIds.has(item.id)}
          disabled={disabledIds?.has(item.id) ?? false}
          onToggle={onToggle}
          onPreview={onPreview}
          actions={actions}
        />
      ))}
    </ul>
  )
}

function MediaCard({
  item,
  mode,
  selected,
  disabled,
  onToggle,
  onPreview,
  actions,
}: {
  item: MediaItem
  mode: 'manage' | 'pick'
  selected: boolean
  disabled: boolean
  onToggle?: (item: MediaItem) => void
  onPreview: (item: MediaItem) => void
  actions?: MediaRowActions | null
}) {
  const trashed = item.state === 'trashed'
  const kind = mediaKind(item.mime_type)
  const Icon = MEDIA_KIND_ICONS[kind]
  const thumb = trashed ? undefined : mediaThumbUrl(item)
  const extension = item.name.includes('.') ? item.name.split('.').at(-1)?.toUpperCase() : undefined
  const picking = mode === 'pick'
  const busy = actions?.busyId === item.id

  const artwork = thumb ? (
    <img
      src={thumb}
      alt=""
      loading="lazy"
      className="size-full object-cover transition-transform duration-200 group-hover:scale-[1.03] motion-reduce:transition-none"
    />
  ) : (
    <span className="flex flex-col items-center gap-1.5 text-muted-foreground">
      <Icon aria-hidden="true" className="size-9" />
      {extension ? <span className="font-medium text-xs tracking-wide">{extension}</span> : null}
    </span>
  )

  return (
    <li
      data-selected={selected || undefined}
      className={cn(
        'group relative flex flex-col overflow-hidden rounded-card border border-border bg-surface shadow-1 transition-shadow hover:shadow-2',
        selected && 'border-primary ring-2 ring-primary/40',
        disabled && 'opacity-60',
      )}
    >
      {picking ? (
        <button
          type="button"
          aria-pressed={selected}
          aria-label={fill(disabled ? text.alreadyAddedNamed : text.selectNamed, { name: item.name })}
          disabled={disabled}
          onClick={() => onToggle?.(item)}
          className="relative flex aspect-4/3 w-full items-center justify-center overflow-hidden bg-muted/60 outline-none focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:ring-inset disabled:cursor-not-allowed"
        >
          {artwork}
          {selected ? (
            <span className="absolute top-2 left-2 flex size-6 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-2">
              <CheckIcon aria-hidden="true" className="size-4" />
            </span>
          ) : null}
        </button>
      ) : (
        <button
          type="button"
          aria-label={fill(text.previewNamed, { name: item.name })}
          disabled={trashed}
          onClick={() => onPreview(item)}
          className="relative flex aspect-4/3 w-full cursor-zoom-in items-center justify-center overflow-hidden bg-muted/60 outline-none focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:ring-inset disabled:cursor-default"
        >
          {artwork}
        </button>
      )}

      {mode === 'manage' && onToggle && !trashed ? (
        // Shown on hover or focus on wide screens, always on touch screens and once selected.
        <span
          className={cn(
            'absolute top-2 left-2 flex rounded-badge bg-surface/90 p-1 shadow-1 transition-opacity',
            !selected && 'sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100',
          )}
        >
          <Checkbox
            checked={selected}
            onCheckedChange={() => onToggle(item)}
            aria-label={fill(text.selectNamed, { name: item.name })}
          />
        </span>
      ) : null}
      {trashed ? (
        <Badge variant="secondary" className="absolute top-2 left-2">
          {text.inTrash}
        </Badge>
      ) : null}

      <div className="flex items-start gap-1 p-2.5">
        <div className="min-w-0 flex-1">
          <p className="truncate font-medium text-sm">{item.name}</p>
          <p className="truncate text-muted-foreground text-xs">
            {mediaTypeLabel(item.mime_type, item.name)} · {formatFileSize(item.size_bytes, text.units)}
          </p>
        </div>
        {picking ? (
          <Hint label={fill(text.previewNamed, { name: item.name })}>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label={fill(text.previewNamed, { name: item.name })}
              onClick={() => onPreview(item)}
            >
              <EyeIcon aria-hidden="true" />
            </Button>
          </Hint>
        ) : (
          <DropdownMenu>
            <Hint label={fill(text.actionsFor, { name: item.name })}>
              <DropdownMenuTrigger
                render={
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    disabled={busy}
                    aria-label={fill(text.actionsFor, { name: item.name })}
                  />
                }
              >
                <EllipsisVerticalIcon aria-hidden="true" />
              </DropdownMenuTrigger>
            </Hint>
            <DropdownMenuContent align="end" className="min-w-44">
              {trashed ? (
                actions ? (
                  <>
                    <DropdownMenuItem onClick={() => actions.onRestore(item)}>
                      <ArchiveRestoreIcon aria-hidden="true" />
                      {text.restore}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      variant="destructive"
                      disabled={item.used_in_count > 0}
                      onClick={() => actions.onPurge(item)}
                    >
                      <Trash2Icon aria-hidden="true" />
                      {text.purge}
                    </DropdownMenuItem>
                  </>
                ) : null
              ) : (
                <>
                  <DropdownMenuItem onClick={() => onPreview(item)}>
                    <EyeIcon aria-hidden="true" />
                    {text.preview}
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    render={<a href={mediaUrls(item.id).open} target="_blank" rel="noopener noreferrer" />}
                  >
                    <ExternalLinkIcon aria-hidden="true" />
                    {text.openInNewTab}
                  </DropdownMenuItem>
                  <DropdownMenuItem render={<a href={mediaUrls(item.id).download} download={item.name} />}>
                    <DownloadIcon aria-hidden="true" />
                    {text.download}
                  </DropdownMenuItem>
                  {actions ? (
                    <>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem onClick={() => actions.onEdit(item)}>
                        <PencilIcon aria-hidden="true" />
                        {text.edit}
                      </DropdownMenuItem>
                      <DropdownMenuItem variant="destructive" onClick={() => actions.onTrash(item)}>
                        <Trash2Icon aria-hidden="true" />
                        {text.confirm.trash.action}
                      </DropdownMenuItem>
                    </>
                  ) : null}
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </div>
    </li>
  )
}
