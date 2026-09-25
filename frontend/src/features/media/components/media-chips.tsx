import { useMemo, useState } from 'react'
import { Lightbox } from '@/components/shared/lightbox'
import { copy, fill } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { formatFileSize } from '@/lib/format/file-size'
import type { MediaItem } from '../api/media-queries'
import { MEDIA_KIND_ICONS, mediaKind, mediaLightboxItem, mediaThumbUrl } from '../media-files'

/**
 * Files shown with a record (a comment's attachments): a chip per file with its thumbnail or type icon,
 * name and size; a chip opens the lightbox over the record's files.
 */
export function MediaChips({ items, label }: { items: readonly MediaItem[]; label?: string }) {
  const timeZone = useSession().session?.tenant.timezone ?? 'UTC'
  const [previewing, setPreviewing] = useState<number | null>(null)
  const lightboxItems = useMemo(
    () => items.map((item) => mediaLightboxItem(item, timeZone)),
    [items, timeZone],
  )

  return (
    <>
      <ul className="flex flex-wrap gap-2" aria-label={label}>
        {items.map((item, index) => {
          const Icon = MEDIA_KIND_ICONS[mediaKind(item.mime_type)]
          const thumb = mediaThumbUrl(item)
          return (
            <li key={item.id}>
              <button
                type="button"
                onClick={() => setPreviewing(index)}
                aria-label={fill(copy.media.previewNamed, { name: item.name })}
                className="flex max-w-64 cursor-zoom-in items-center gap-2 rounded-lg border border-border bg-surface p-1 pe-3 text-left text-sm shadow-1 outline-none transition-colors hover:bg-muted focus-visible:ring-3 focus-visible:ring-ring/50"
              >
                <span className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-control bg-muted text-muted-foreground">
                  {thumb ? (
                    <img src={thumb} alt="" loading="lazy" className="size-full object-cover" />
                  ) : (
                    <Icon aria-hidden="true" className="size-4" />
                  )}
                </span>
                <span className="min-w-0">
                  <span className="block truncate font-medium">{item.name}</span>
                  <span className="block text-muted-foreground text-xs">
                    {formatFileSize(item.size_bytes, copy.media.units)}
                  </span>
                </span>
              </button>
            </li>
          )
        })}
      </ul>
      <Lightbox
        items={lightboxItems}
        index={previewing}
        onIndexChange={setPreviewing}
        onClose={() => setPreviewing(null)}
      />
    </>
  )
}
