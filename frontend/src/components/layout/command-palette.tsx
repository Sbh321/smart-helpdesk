import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import type { LucideIcon } from 'lucide-react'
import { BookOpenIcon, CornerDownLeftIcon, PlusIcon, SearchIcon, TicketIcon } from 'lucide-react'
import { type KeyboardEvent, useEffect, useId, useMemo, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Kbd, KbdGroup } from '@/components/ui/kbd'
import { copy, fill } from '@/copy/en'
import { api, unwrapBody } from '@/lib/api/client'
import { hasPermission, useSession } from '@/lib/auth'
import { useRuntimeConfig } from '@/lib/config'
import { useDebouncedValue } from '@/lib/use-debounced-value'
import { cn } from '@/lib/utils'
import { visibleNavItems } from './nav-items'

interface PaletteItem {
  id: string
  group: 'tickets' | 'commands'
  label: string
  hint?: string
  icon: LucideIcon
  run: () => void
}

/**
 * Search and commands (docs/06-design-system/components.md §CommandPalette). ⌘K / Ctrl+K or the search box
 * in the top bar opens a dialog with one search field: typing filters the pages and commands the session
 * may use and searches tickets by number or words (`GET /v1/tickets?search=`); ↑ and ↓ move, Enter runs.
 * The field is a combobox over a listbox, so the active option is announced while focus stays in the field.
 */
export function CommandPalette({ workspace }: { workspace: string }) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const { permissions } = useSession()
  const { docsUrl } = useRuntimeConfig()
  const navigate = useNavigate()
  const listId = useId()
  const text = copy.shell.commandPalette
  const canSearchTickets = hasPermission(permissions, 'tickets.view')
  const search = useDebouncedValue(query.trim(), 200)

  useEffect(() => {
    const onKeyDown = (event: globalThis.KeyboardEvent) => {
      if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault()
        setOpen((previous) => !previous)
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  const close = () => {
    setOpen(false)
    setQuery('')
    setActive(0)
  }

  const tickets = useQuery({
    queryKey: ['command-palette', 'tickets', workspace, search],
    queryFn: () =>
      unwrapBody(api().GET('/tickets', { params: { query: { search, per_page: 5, sort: '-updated_at' } } })),
    enabled: open && canSearchTickets && search.length > 0,
    staleTime: 10_000,
  })

  const items = useMemo<PaletteItem[]>(() => {
    const needle = query.trim().toLowerCase()
    const commands: PaletteItem[] = [
      ...visibleNavItems(permissions).map((item) => ({
        id: `nav-${item.key}`,
        group: 'commands' as const,
        label: item.label,
        hint: text.goTo,
        icon: item.icon,
        run: () => void navigate({ to: item.to, params: { workspace } }),
      })),
      ...(hasPermission(permissions, 'tickets.create')
        ? [
            {
              id: 'new-ticket',
              group: 'commands' as const,
              label: text.newTicket,
              icon: PlusIcon,
              run: () => void navigate({ to: '/$workspace/tickets/new', params: { workspace } }),
            },
          ]
        : []),
      ...(hasPermission(permissions, 'integrations.manage')
        ? [
            {
              id: 'api-reference',
              group: 'commands' as const,
              label: copy.nav.apiReference,
              hint: text.newTab,
              icon: BookOpenIcon,
              run: () => void window.open(docsUrl, '_blank', 'noopener,noreferrer'),
            },
          ]
        : []),
    ].filter((item) => needle === '' || item.label.toLowerCase().includes(needle))

    const found: PaletteItem[] = (tickets.data?.data ?? []).map((ticket) => ({
      id: `ticket-${ticket.id}`,
      group: 'tickets' as const,
      label: `#${ticket.number} ${ticket.title}`,
      icon: TicketIcon,
      run: () =>
        void navigate({ to: '/$workspace/tickets/$ticketId', params: { workspace, ticketId: ticket.id } }),
    }))
    const searchAll: PaletteItem[] =
      canSearchTickets && needle !== ''
        ? [
            {
              id: 'search-all',
              group: 'tickets',
              label: fill(text.searchAll, { query: query.trim() }),
              icon: SearchIcon,
              run: () =>
                void navigate({
                  to: '/$workspace/tickets',
                  params: { workspace },
                  search: { search: query.trim() },
                }),
            },
          ]
        : []

    return [...found, ...searchAll, ...commands]
  }, [query, permissions, tickets.data, canSearchTickets, docsUrl, navigate, workspace, text])

  const runItem = (item: PaletteItem | undefined) => {
    if (!item) return
    close()
    item.run()
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActive((index) => Math.min(index + 1, items.length - 1))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActive((index) => Math.max(index - 1, 0))
    } else if (event.key === 'Enter') {
      event.preventDefault()
      runItem(items[active])
    }
  }

  const groups: { key: PaletteItem['group']; label: string }[] = [
    { key: 'tickets', label: text.ticketsGroup },
    { key: 'commands', label: text.navigateGroup },
  ]

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? setOpen(true) : close())}>
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => setOpen(true)}
        aria-label={text.open}
        className="w-full justify-start gap-2 text-muted-foreground"
      >
        <SearchIcon aria-hidden="true" />
        <span className="hidden truncate sm:inline">{text.trigger}</span>
        <KbdGroup className="ms-auto hidden sm:inline-flex">
          <Kbd>Ctrl</Kbd>
          <Kbd>K</Kbd>
        </KbdGroup>
      </Button>

      <DialogContent className="gap-0 p-0 sm:max-w-xl">
        <DialogHeader className="sr-only">
          <DialogTitle>{text.title}</DialogTitle>
          <DialogDescription>{text.description}</DialogDescription>
        </DialogHeader>
        <div className="flex items-center gap-2 border-border border-b px-4">
          <SearchIcon aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
          <input
            autoFocus
            role="combobox"
            aria-expanded="true"
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={items[active] ? `${listId}-${items[active].id}` : undefined}
            aria-label={text.inputLabel}
            placeholder={canSearchTickets ? text.placeholder : text.placeholderCommands}
            value={query}
            onChange={(event) => {
              setQuery(event.target.value)
              setActive(0)
            }}
            onKeyDown={onKeyDown}
            className="h-12 w-full bg-transparent text-base outline-none placeholder:text-muted-foreground"
          />
        </div>

        <div
          id={listId}
          role="listbox"
          aria-label={text.title}
          className="max-h-[min(60dvh,26rem)] overflow-y-auto p-2"
        >
          {items.length === 0 ? (
            <p className="px-3 py-6 text-center text-muted-foreground text-sm">
              {tickets.isFetching ? text.searching : fill(text.empty, { query: query.trim() })}
            </p>
          ) : (
            groups.map(({ key, label }) => {
              const groupItems = items.filter((item) => item.group === key)
              if (groupItems.length === 0) return null
              return (
                // biome-ignore lint/a11y/useSemanticElements: an option group inside a listbox is a div with role group (ARIA), not a fieldset.
                <div key={key} role="group" aria-label={label} className="mb-1">
                  <p className="px-2 pt-2 pb-1 font-medium text-muted-foreground text-xs">{label}</p>
                  {groupItems.map((item) => {
                    const index = items.indexOf(item)
                    const Icon = item.icon
                    return (
                      <div
                        key={item.id}
                        id={`${listId}-${item.id}`}
                        role="option"
                        aria-label={item.label}
                        aria-selected={index === active}
                        tabIndex={-1}
                        onMouseMove={() => setActive(index)}
                        onClick={() => runItem(item)}
                        onKeyDown={(event) => event.key === 'Enter' && runItem(item)}
                        className={cn(
                          'flex items-center gap-2 rounded-lg px-2 py-2 text-sm',
                          index === active && 'bg-option-highlight text-foreground',
                        )}
                      >
                        <Icon aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                        <span className="min-w-0 flex-1 truncate">{item.label}</span>
                        {item.hint ? (
                          <span className="text-muted-foreground text-xs">{item.hint}</span>
                        ) : null}
                        {index === active ? (
                          <CornerDownLeftIcon aria-hidden="true" className="size-3.5 text-muted-foreground" />
                        ) : null}
                      </div>
                    )
                  })}
                </div>
              )
            })
          )}
        </div>
        <p className="border-border border-t px-4 py-2 text-muted-foreground text-xs">{text.help}</p>
      </DialogContent>
    </Dialog>
  )
}
