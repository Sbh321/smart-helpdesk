import { dataTableColumnHelper } from '@/components/shared/data-table'
import { Badge } from '@/components/ui/badge'
import { copy } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import type { Contact } from '../api/contact-queries'

const helper = dataTableColumnHelper<Contact>()
const none = <span className="text-muted-foreground">{copy.contacts.none}</span>

/** Contact list columns; the ids of sortable columns are the API's sort fields. */
export function contactColumns(timeZone: string) {
  return helper.columns([
    helper.accessor('name', {
      enableSorting: true,
      enableHiding: false,
      meta: { label: copy.contacts.columns.name, className: 'font-medium' },
      cell: (info) =>
        info.row.original.archived_at ? (
          <span className="inline-flex items-center gap-2">
            {info.getValue()}
            <Badge variant="secondary">{copy.contacts.list.archived}</Badge>
          </span>
        ) : (
          info.getValue()
        ),
    }),
    helper.accessor('email', {
      enableSorting: true,
      meta: { label: copy.contacts.columns.email },
    }),
    helper.accessor('phone', {
      meta: { label: copy.contacts.columns.phone },
      cell: (info) => info.getValue() ?? none,
    }),
    helper.accessor((contact) => contact.organization?.name ?? null, {
      id: 'organization',
      meta: { label: copy.contacts.columns.organization },
      cell: (info) => info.getValue() ?? none,
    }),
    helper.accessor('tags', {
      meta: { label: copy.contacts.columns.tags },
      cell: (info) => {
        const tags = info.getValue()
        if (tags.length === 0) return none
        return (
          <ul className="flex flex-wrap gap-1">
            {tags.map((tag) => (
              <li key={tag.id}>
                <Badge variant="outline">{tag.name}</Badge>
              </li>
            ))}
          </ul>
        )
      },
    }),
    helper.accessor('last_ticket_at', {
      enableSorting: true,
      meta: { label: copy.contacts.columns.lastTicketAt, className: 'tabular-nums' },
      cell: (info) => {
        const value = info.getValue()
        return value ? formatInZone(value, timeZone, 'd MMM yyyy') : none
      },
    }),
    helper.accessor('created_at', {
      enableSorting: true,
      meta: { label: copy.contacts.columns.createdAt, className: 'tabular-nums' },
      cell: (info) => formatInZone(info.getValue(), timeZone, 'd MMM yyyy'),
    }),
  ])
}
