import { createFileRoute, Link, Outlet } from '@tanstack/react-router'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { PageHeader } from '@/components/shared/page-header'
import { copy } from '@/copy/en'
import { useCan } from '@/lib/auth'

export const Route = createFileRoute('/$workspace/_app/settings')({
  component: SettingsLayout,
  staticData: { crumb: copy.settings.title },
})

type SectionPermission =
  | 'settings.manage'
  | 'agents.view'
  | 'tickets.view'
  | 'media.view'
  | 'users.manage'
  | 'roles.manage'
  | 'integrations.manage'

const sections = [
  { slug: 'general', label: copy.workspaceSettings.general, permission: 'settings.manage' },
  { slug: 'branding', label: copy.workspaceSettings.branding, permission: 'settings.manage' },
  { slug: 'automation', label: copy.workspaceSettings.automation, permission: 'settings.manage' },
  { slug: 'tickets', label: copy.workspaceSettings.tickets, permission: 'settings.manage' },
  { slug: 'users', label: copy.users.title, permission: 'users.manage' },
  { slug: 'roles', label: copy.roles.title, permission: 'roles.manage' },
  { slug: 'skills', label: copy.settings.skills, permission: 'agents.view' },
  { slug: 'teams', label: copy.settings.teams, permission: 'agents.view' },
  { slug: 'categories', label: copy.settings.categories, permission: 'tickets.view' },
  { slug: 'agents', label: copy.settings.agents, permission: 'agents.view' },
  { slug: 'shifts', label: copy.settings.shifts, permission: 'agents.view' },
  { slug: 'sla', label: copy.sla.policies, permission: 'tickets.view' },
  { slug: 'calendars', label: copy.sla.calendars, permission: 'tickets.view' },
  { slug: 'priority', label: copy.priority.settingsTitle, permission: 'settings.manage' },
  { slug: 'media', label: copy.media.title, permission: 'media.view' },
  { slug: 'api-clients', label: copy.apiClients.nav, permission: 'integrations.manage' },
] as const satisfies readonly { slug: string; label: string; permission: SectionPermission }[]

function SettingsLayout() {
  const { workspace } = Route.useParams()
  const granted: Record<SectionPermission, boolean> = {
    'settings.manage': useCan('settings.manage'),
    'agents.view': useCan('agents.view'),
    'tickets.view': useCan('tickets.view'),
    'media.view': useCan('media.view'),
    'users.manage': useCan('users.manage'),
    'roles.manage': useCan('roles.manage'),
    'integrations.manage': useCan('integrations.manage'),
  }
  const visible = sections.filter((section) => granted[section.permission])

  return (
    <>
      <PageHeader title={copy.settings.title} description={copy.settings.description} />
      {visible.length > 0 ? (
        <div className="grid gap-6 lg:grid-cols-[12rem_minmax(0,1fr)]">
          <nav aria-label={copy.settings.sections} className="flex flex-wrap gap-2 lg:flex-col">
            {visible.map((section) => (
              <Link
                key={section.slug}
                to={`/$workspace/settings/${section.slug}`}
                params={{ workspace }}
                className="rounded-lg px-3 py-2 text-sm hover:bg-accent [&.active]:bg-accent [&.active]:font-medium"
              >
                {section.label}
              </Link>
            ))}
          </nav>
          <div className="min-w-0">
            <Outlet />
          </div>
        </div>
      ) : (
        <ForbiddenState />
      )}
    </>
  )
}
