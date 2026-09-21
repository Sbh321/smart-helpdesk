import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { copy } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { useListParams } from '@/lib/list-params'
import { useDebouncedValue } from '@/lib/use-debounced-value'
import {
  agentQueries,
  createTeam,
  directoryListSchema,
  replaceTeamMembers,
  type Team,
  type TeamInput,
  teamQueries,
  updateTeam,
} from '../api/agent-queries'
import { type TeamFormValues, teamFormSchema } from '../schemas'
import { DirectoryMultiPicker } from './directory-multi-picker'
import {
  type Editing,
  FORM_CLASS,
  FormActions,
  PICKER_PAGE,
  Saved,
  Section,
  useDirectory,
} from './directory-shared'
import { DirectoryTable } from './directory-table'

function initialValues(team: Team | null): TeamFormValues {
  return {
    name: team?.name ?? '',
    description: team?.description ?? '',
    members:
      team?.members.map((member) => ({ value: member.id, label: member.name, detail: member.email })) ?? [],
  }
}

function toInput(values: TeamFormValues): TeamInput {
  return { name: values.name.trim(), description: values.description.trim() }
}

function TeamForm({
  team,
  onSaved,
  onCancel,
}: {
  team: Team | null
  onSaved: () => void
  onCancel: () => void
}) {
  const { tenantId, client } = useDirectory()
  const server = useServerErrors()
  const teamId = useRef(team?.id)
  const [memberSearch, setMemberSearch] = useState('')
  const debouncedMemberSearch = useDebouncedValue(memberSearch)
  const agents = useQuery({
    ...agentQueries.list(tenantId, { ...PICKER_PAGE, sort: 'created_at', search: debouncedMemberSearch }),
    enabled: tenantId !== '',
  })
  const form = useForm({
    defaultValues: initialValues(team),
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: teamFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        const saved = teamId.current
          ? await updateTeam(teamId.current, toInput(value))
          : await createTeam(toInput(value))
        // Two requests: if the members fail, the next submit updates the Team instead of creating it again.
        teamId.current = saved.id
        await replaceTeamMembers(
          saved.id,
          value.members.map((member) => member.value),
        )
        await client.invalidateQueries({ queryKey: queryKeys.teams.all(tenantId) })
        onSaved()
      } catch (error) {
        server.capture(error)
        if (teamId.current) void client.invalidateQueries({ queryKey: queryKeys.teams.all(tenantId) })
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={team ? copy.settings.editNamed.replace('{name}', team.name) : copy.settings.addTeam}
      className={FORM_CLASS}
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={copy.settings.failed} error={server.failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id="team-name"
              label={copy.settings.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="description">
          {(field) => (
            <TextField
              id="team-description"
              label={copy.settings.descriptionLabel}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.description)}
            />
          )}
        </form.Field>
        <form.Field name="members">
          {(field) => (
            <DirectoryMultiPicker
              id="team-members"
              label={copy.settings.members}
              selected={field.state.value}
              options={
                agents.data?.data.map((agent) => ({
                  value: agent.id,
                  label: agent.user.name,
                  detail: agent.user.email,
                })) ?? []
              }
              onQueryChange={setMemberSearch}
              onChange={field.handleChange}
              isLoading={agents.isFetching}
              errors={mergeMessages(field.state.meta.errors, server.fields.agent_ids)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <FormActions isSubmitting={isSubmitting} saveLabel={copy.settings.saveTeam} onCancel={onCancel} />
        )}
      </form.Subscribe>
    </form>
  )
}

export function TeamSettings() {
  const allowed = useCan('agents.view')
  const canManage = useCan('teams.manage')
  const { tenantId } = useDirectory()
  const list = useListParams(directoryListSchema)
  const teams = useQuery({
    ...teamQueries.list(tenantId, list.apiQuery),
    enabled: allowed && tenantId !== '',
  })
  const [editing, setEditing] = useState<Editing<Team>>(undefined)
  const [saved, setSaved] = useState(false)
  if (!allowed) return <ForbiddenState />
  return (
    <Section title={copy.settings.teams}>
      {canManage ? (
        <Button
          onClick={() => {
            setSaved(false)
            setEditing(null)
          }}
        >
          {copy.settings.addTeam}
        </Button>
      ) : null}
      <Saved show={saved && editing === undefined} />
      {editing !== undefined ? (
        <TeamForm
          key={editing?.id ?? 'new'}
          team={editing}
          onCancel={() => setEditing(undefined)}
          onSaved={() => {
            setSaved(true)
            setEditing(undefined)
          }}
        />
      ) : null}
      {teams.isPending ? (
        <p aria-busy="true">{copy.settings.loading}</p>
      ) : teams.isError ? (
        <ErrorState error={teams.error} onRetry={() => void teams.refetch()} />
      ) : (
        <DirectoryTable
          id="settings-teams"
          label={copy.settings.teams}
          rows={teams.data.data.map((team) => ({
            id: team.id,
            name: team.name,
            details: team.members.map((member) => member.name).join(', ') || copy.settings.none,
          }))}
          total={teams.data.meta.total}
          list={list}
          defaultSort={directoryListSchema.defaultSort}
          sortableName
          onEdit={
            canManage
              ? (id) => {
                  const team = teams.data.data.find((row) => row.id === id)
                  if (!team) return
                  setSaved(false)
                  setEditing(team)
                }
              : undefined
          }
        />
      )}
    </Section>
  )
}
