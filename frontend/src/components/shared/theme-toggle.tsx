import { type LucideIcon, Monitor, Moon, Sun } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Hint } from '@/components/ui/tooltip'
import { copy } from '@/copy/en'
import { type ResolvedTheme, THEME_CHOICES, type ThemeChoice, useTheme } from '@/lib/theme'
import { cn } from '@/lib/utils'

const icons: Record<ThemeChoice, LucideIcon> = { light: Sun, dark: Moon, system: Monitor }

function announcementFor(choice: ThemeChoice, resolved: ResolvedTheme): string {
  const { announcement } = copy.theme
  if (choice === 'system') {
    return resolved === 'dark' ? announcement.systemDark : announcement.systemLight
  }
  return announcement[choice]
}

/**
 * Light / Dark / System segmented control (themes.md). Each option is a toggle button with a visible
 * label (read out but hidden on narrow screens with `compact`); the resulting theme is announced through a
 * polite live region.
 */
export function ThemeToggle({
  className,
  compact: _compact = false,
}: {
  className?: string
  /** @deprecated The control is always icons only now; kept so existing callers still compile. */
  compact?: boolean
}) {
  const { theme, resolvedTheme, setTheme } = useTheme()
  const [announced, setAnnounced] = useState<ThemeChoice | null>(null)

  const choose = (next: ThemeChoice) => {
    setTheme(next)
    setAnnounced(next)
  }

  return (
    <fieldset
      className={cn(
        'inline-flex items-center gap-1 rounded-lg border border-border bg-surface p-1',
        className,
      )}
    >
      <legend className="sr-only">{copy.theme.label}</legend>
      {THEME_CHOICES.map((choice) => {
        const Icon = icons[choice]
        const selected = theme === choice
        return (
          <Hint key={choice} label={copy.theme[choice]}>
            <Button
              type="button"
              size="icon-sm"
              variant={selected ? 'outline' : 'ghost'}
              aria-pressed={selected}
              onClick={() => choose(choice)}
              // The selected option carries a --input border so the state indicator reaches 3:1 (WCAG 1.4.11).
              className={cn(selected ? 'border-input bg-surface text-foreground' : 'text-muted-foreground')}
            >
              <Icon aria-hidden="true" />
              <span className="sr-only">{copy.theme[choice]}</span>
            </Button>
          </Hint>
        )
      })}
      <span role="status" aria-live="polite" className="sr-only">
        {announced === null ? '' : announcementFor(announced, resolvedTheme)}
      </span>
    </fieldset>
  )
}
