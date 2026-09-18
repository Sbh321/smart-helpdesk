import type { LucideIcon } from 'lucide-react'
import { Building2Icon, GaugeIcon, SettingsIcon, TicketIcon, UsersIcon } from 'lucide-react'
import { copy } from '@/copy/en'
import { hasPermission } from '@/lib/auth'

/** The routes the sidebar and the command palette can reach. */
export type NavTo =
  | '/$workspace'
  | '/$workspace/tickets'
  | '/$workspace/contacts'
  | '/$workspace/organizations'
  | '/$workspace/settings'

export interface NavItem {
  key: string
  label: string
  icon: LucideIcon
  /** Typed route path; the workspace segment is filled in from the URL. */
  to: NavTo
  /** Hidden unless the session holds this permission; `undefined` means always visible. */
  permission?: string
}

/**
 * Primary navigation. Items are gated by permission, never by role (CLAUDE.md); until roles land
 * (roadmap M1-09) `/v1/me` returns an empty list, so only the dashboard shows.
 */
export const NAV_ITEMS: readonly NavItem[] = [
  { key: 'dashboard', label: copy.nav.dashboard, icon: GaugeIcon, to: '/$workspace' },
  {
    key: 'tickets',
    label: copy.nav.tickets,
    icon: TicketIcon,
    to: '/$workspace/tickets',
    permission: 'tickets.view',
  },
  {
    key: 'contacts',
    label: copy.nav.contacts,
    icon: UsersIcon,
    to: '/$workspace/contacts',
    permission: 'contacts.view',
  },
  {
    key: 'organizations',
    label: copy.nav.organizations,
    icon: Building2Icon,
    to: '/$workspace/organizations',
    permission: 'contacts.view',
  },
  {
    key: 'settings',
    label: copy.nav.settings,
    icon: SettingsIcon,
    to: '/$workspace/settings',
    permission: 'settings.manage',
  },
]

/** Items the session may see. Permissions, never roles (CLAUDE.md). */
export function visibleNavItems(permissions: readonly string[]): NavItem[] {
  return NAV_ITEMS.filter(
    (item) => item.permission === undefined || hasPermission(permissions, item.permission),
  )
}
