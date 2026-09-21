import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { SearchFilter } from '@/components/shared/data-table'
import { ErrorState } from '@/components/shared/error-state'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { formatFileSize } from '@/lib/format/file-size'
import { type MediaItem, mediaQueries } from '../api/media-queries'

/** "Pick from library": the 25 newest Media items, narrowed by a debounced search. */
export function MediaPicker({ onPick }: { onPick: (item: MediaItem) => void }) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const items = useQuery({
    ...mediaQueries.list(tenantId, {
      page: 1,
      per_page: 25,
      sort: '-created_at',
      ...(search ? { search } : {}),
    }),
    enabled: open && tenantId !== '',
  })

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (!next) setSearch('')
      }}
    >
      <DialogTrigger render={<Button type="button" variant="outline" />}>{copy.media.pick}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{copy.media.pickTitle}</DialogTitle>
          <DialogDescription>{copy.media.pickDescription}</DialogDescription>
        </DialogHeader>
        <SearchFilter
          label={copy.media.search}
          placeholder={copy.media.search}
          value={search}
          onChange={(value) => setSearch(value ?? '')}
          shortcut={false}
        />
        {items.isPending ? (
          <Skeleton className="h-24 w-full" />
        ) : items.isError ? (
          <ErrorState error={items.error} onRetry={() => void items.refetch()} />
        ) : items.data.data.length === 0 ? (
          <p className="text-sm text-muted-foreground">{copy.media.empty}</p>
        ) : (
          <ul className="max-h-80 space-y-2 overflow-y-auto">
            {items.data.data.map((item) => (
              <li
                key={item.id}
                className="flex items-center gap-2 rounded-md border border-border p-2 text-sm"
              >
                <span className="min-w-0 flex-1 truncate">{item.name}</span>
                <span className="text-muted-foreground">
                  {formatFileSize(item.size_bytes, copy.media.units)}
                </span>
                <Button
                  type="button"
                  size="sm"
                  aria-label={fill(copy.media.useNamed, { name: item.name })}
                  onClick={() => {
                    onPick(item)
                    setOpen(false)
                  }}
                >
                  {copy.media.useItem}
                </Button>
              </li>
            ))}
          </ul>
        )}
        <DialogClose render={<Button type="button" variant="outline" />}>{copy.media.cancel}</DialogClose>
      </DialogContent>
    </Dialog>
  )
}
