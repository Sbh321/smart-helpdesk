import { MonitorIcon, MoonIcon, Rows3Icon, Rows4Icon, SunIcon } from 'lucide-react'
import { useState } from 'react'
import {
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
} from '@/components/ui/dropdown-menu'
import { copy } from '@/copy/en'
import { type Density, type ResolvedTheme, THEME_CHOICES, type ThemeChoice, useTheme } from '@/lib/theme'

const themeIcons = { light: SunIcon, dark: MoonIcon, system: MonitorIcon } as const
const densityIcons = { comfortable: Rows3Icon, compact: Rows4Icon } as const
const DENSITIES: readonly Density[] = ['comfortable', 'compact']

function themeAnnouncement(choice: ThemeChoice, resolved: ResolvedTheme): string {
  const { announcement } = copy.theme
  if (choice === 'system') {
    return resolved === 'dark' ? announcement.systemDark : announcement.systemLight
  }
  return announcement[choice]
}

/**
 * Theme and density, as two radio groups inside the account menu (roadmap M4-02). They used to be a
 * three-button segmented control in the top bar — prime space for a setting changed once a month
 * (docs/06-design-system/ux-review.md G7) — and density had no control at all, although the tokens
 * supported it since milestone 1.
 *
 * Rendered inside an open `DropdownMenuContent`; the change is announced politely, because the menu
 * closes on selection and the only other feedback is the page restyling itself.
 */
export function AppearanceMenu() {
  const { theme, resolvedTheme, setTheme, density, setDensity } = useTheme()
  const [announcement, setAnnouncement] = useState('')

  return (
    <>
      <DropdownMenuGroup>
        <DropdownMenuLabel>{copy.theme.label}</DropdownMenuLabel>
        <DropdownMenuRadioGroup
          value={theme}
          onValueChange={(next) => {
            const choice = next as ThemeChoice
            setTheme(choice)
            setAnnouncement(themeAnnouncement(choice, resolvedTheme))
          }}
        >
          {THEME_CHOICES.map((choice) => {
            const Icon = themeIcons[choice]
            return (
              <DropdownMenuRadioItem key={choice} value={choice} closeOnClick={false}>
                <Icon aria-hidden="true" className="size-4" />
                {copy.theme[choice]}
              </DropdownMenuRadioItem>
            )
          })}
        </DropdownMenuRadioGroup>
      </DropdownMenuGroup>

      <DropdownMenuGroup>
        <DropdownMenuLabel>{copy.density.label}</DropdownMenuLabel>
        <DropdownMenuRadioGroup
          value={density}
          onValueChange={(next) => {
            const choice = next as Density
            setDensity(choice)
            setAnnouncement(copy.density.announcement[choice])
          }}
        >
          {DENSITIES.map((choice) => {
            const Icon = densityIcons[choice]
            return (
              <DropdownMenuRadioItem key={choice} value={choice} closeOnClick={false}>
                <Icon aria-hidden="true" className="size-4" />
                {copy.density[choice]}
              </DropdownMenuRadioItem>
            )
          })}
        </DropdownMenuRadioGroup>
      </DropdownMenuGroup>

      <span className="sr-only" role="status" aria-live="polite">
        {announcement}
      </span>
    </>
  )
}
