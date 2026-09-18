import { Link } from '@tanstack/react-router'
import { SearchIcon } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Kbd, KbdGroup } from '@/components/ui/kbd'
import { copy } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { visibleNavItems } from './nav-items'

/**
 * Command palette shell (docs/06-design-system/components.md §CommandPalette). ⌘K / Ctrl+K opens a
 * dialog listing the navigation targets the session may reach. Ticket search and actions — and the
 * `cmdk` dependency that powers them — arrive with the ticket list (roadmap M2-10).
 */
export function CommandPalette({ workspace }: { workspace: string }) {
  const [open, setOpen] = useState(false)
  const { permissions } = useSession()
  const items = visibleNavItems(permissions)

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault()
        setOpen((previous) => !previous)
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => setOpen(true)}
        aria-label={copy.shell.commandPalette.open}
        className="gap-2 text-muted-foreground"
      >
        <SearchIcon aria-hidden="true" />
        <span className="hidden sm:inline">{copy.shell.commandPalette.open}</span>
        <KbdGroup className="hidden sm:inline-flex">
          <Kbd>Ctrl</Kbd>
          <Kbd>K</Kbd>
        </KbdGroup>
      </Button>

      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{copy.shell.commandPalette.title}</DialogTitle>
          <DialogDescription>{copy.shell.commandPalette.description}</DialogDescription>
        </DialogHeader>
        <div>
          <p className="px-1 pb-1 text-xs font-medium text-muted-foreground">
            {copy.shell.commandPalette.navigateGroup}
          </p>
          <ul className="flex flex-col gap-0.5">
            {items.map((item) => {
              const Icon = item.icon
              return (
                <li key={item.key}>
                  <Link
                    to={item.to}
                    params={{ workspace }}
                    onClick={() => setOpen(false)}
                    className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                  >
                    <Icon aria-hidden="true" className="size-4" />
                    {item.label}
                  </Link>
                </li>
              )
            })}
          </ul>
        </div>
        <p className="text-xs text-muted-foreground">{copy.shell.commandPalette.soon}</p>
      </DialogContent>
    </Dialog>
  )
}
