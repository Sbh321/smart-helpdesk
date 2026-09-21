import type { LucideIcon } from 'lucide-react'
import { BarChart3Icon, Building2Icon, GaugeIcon, SettingsIcon, TicketIcon, UsersIcon } from 'lucide-react'
import { copy } from '@/copy/en'
import { hasPermission } from '@/lib/auth'

/** The routes the sidebar and the command palette can reach. */
export type NavTo =
  | '/$workspace'
  | '/$workspace/tickets'
  | '/$workspace/contacts'
  | '/$workspace/organizations'
  | '/$workspace/reports'
  | '/$workspace/settings'

export interface NavItem {
  key: string
  label: string
  icon: LucideIcon
  /** Typed route path; the workspace segment is filled in from the URL. */
  to: NavTo
  /** Hidden unless the session holds this permission, or any one of a list; `undefined` means always visible. */
  permission?: string | readonly string[]
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
    key: 'reports',
    label: copy.nav.reports,
    icon: BarChart3Icon,
    to: '/$workspace/reports',
    permission: 'reports.view',
  },
  {
    key: 'settings',
    label: copy.nav.settings,
    icon: SettingsIcon,
    to: '/$workspace/settings',
    // Settings holds pages for several kinds of administrator, not only workspace settings.
    permission: [
      'settings.manage',
      'users.manage',
      'roles.manage',
      'agents.manage',
      'teams.manage',
      'shifts.manage',
      'sla.manage',
      'calendars.manage',
      'media.manage',
    ],
  },
]

/** Items the session may see. Permissions, never roles (CLAUDE.md). */
export function visibleNavItems(permissions: readonly string[]): NavItem[] {
  return NAV_ITEMS.filter((item) => {
    if (item.permission === undefined) return true
    const needed = typeof item.permission === 'string' ? [item.permission] : item.permission
    return needed.some((permission) => hasPermission(permissions, permission))
  })
}
