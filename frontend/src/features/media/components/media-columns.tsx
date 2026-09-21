import { dataTableColumnHelper } from '@/components/shared/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { apiUrl } from '@/lib/api/client'
import { formatInZone } from '@/lib/datetime/format'
import { formatFileSize } from '@/lib/format/file-size'
import type { MediaItem } from '../api/media-queries'

const helper = dataTableColumnHelper<MediaItem>()
const none = <span className="text-muted-foreground">{copy.media.none}</span>

export interface MediaRowActions {
  onEdit: (item: MediaItem) => void
  onTrash: (item: MediaItem) => void
  onRestore: (item: MediaItem) => void
  onPurge: (item: MediaItem) => void
  /** The id of the row a request is running for; its buttons are disabled. */
  busyId: string | null
}

/** Media library columns; the ids of sortable columns are the API's sort fields. */
export function mediaColumns(timeZone: string, actions: MediaRowActions | null) {
  return helper.columns([
    helper.accessor('name', {
      enableSorting: true,
      enableHiding: false,
      meta: { label: copy.media.columns.name, className: 'font-medium' },
      cell: (info) => {
        const item = info.row.original
        return (
          <span className="flex min-w-0 items-center gap-3">
            {item.variants.thumb ? (
              <img
                className="size-10 shrink-0 rounded object-cover"
                src={apiUrl(`/v1/media/${item.id}/variants/thumb`)}
                alt=""
                loading="lazy"
              />
            ) : (
              <span aria-hidden="true" className="size-10 shrink-0 rounded bg-muted" />
            )}
            <a
              className="min-w-0 truncate text-primary underline-offset-2 hover:underline"
              href={apiUrl(`/v1/media/${item.id}/download`)}
            >
              {item.name}
            </a>
          </span>
        )
      },
    }),
    helper.accessor('size_bytes', {
      enableSorting: true,
      meta: { label: copy.media.columns.size, className: 'tabular-nums' },
      cell: (info) => formatFileSize(info.getValue(), copy.media.units),
    }),
    helper.accessor('tags', {
      meta: { label: copy.media.tags },
      cell: (info) => {
        const tags = info.getValue()
        if (tags.length === 0) return none
        return (
          <ul className="flex flex-wrap gap-1">
            {tags.map((tag) => (
              <li key={tag.slug}>
                <Badge variant="outline">{tag.name}</Badge>
              </li>
            ))}
          </ul>
        )
      },
    }),
    helper.accessor('used_in_count', {
      meta: { label: copy.media.columns.usedIn },
      cell: (info) => {
        const tickets = info.row.original.used_in_tickets
        if (info.getValue() === 0) return none
        return tickets.length > 0
          ? tickets.map((ticket) => fill(copy.tickets.number, { number: ticket.number })).join(', ')
          : fill(copy.media.usedCount, { count: info.getValue() })
      },
    }),
    helper.accessor('created_at', {
      enableSorting: true,
      meta: { label: copy.media.columns.createdAt, className: 'tabular-nums' },
      cell: (info) => formatInZone(info.getValue(), timeZone, 'd MMM yyyy'),
    }),
    ...(actions
      ? [
          helper.display({
            id: 'actions',
            enableHiding: false,
            meta: { label: copy.media.columns.actions, className: 'text-right' },
            cell: (info) => {
              const item = info.row.original
              const busy = actions.busyId === item.id
              return item.state === 'trashed' ? (
                <span className="flex justify-end gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={busy}
                    aria-label={fill(copy.media.restoreNamed, { name: item.name })}
                    onClick={() => actions.onRestore(item)}
                  >
                    {copy.media.restore}
                  </Button>
                  <Button
                    size="sm"
                    variant="destructive"
                    disabled={busy || item.used_in_count > 0}
                    aria-label={fill(copy.media.purgeNamed, { name: item.name })}
                    onClick={() => actions.onPurge(item)}
                  >
                    {copy.media.purge}
                  </Button>
                </span>
              ) : (
                <span className="flex justify-end gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    aria-label={fill(copy.media.editNamed, { name: item.name })}
                    onClick={() => actions.onEdit(item)}
                  >
                    {copy.media.edit}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    aria-label={fill(copy.media.trashNamed, { name: item.name })}
                    onClick={() => actions.onTrash(item)}
                  >
                    {copy.media.trash}
                  </Button>
                </span>
              )
            },
          }),
        ]
      : []),
  ])
}
