import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { EntityCombobox } from '@/components/shared/entity-combobox'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SelectField } from '@/components/shared/select-field'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { useListParams } from '@/lib/list-params'
import { useDebouncedValue } from '@/lib/use-debounced-value'
import {
  type Agent,
  agentListSchema,
  agentQueries,
  createAgent,
  skillQueries,
  teamQueries,
  updateAgent,
} from '../api/agent-queries'
import {
  AGENT_AVAILABILITIES,
  type AgentSettingsFormValues,
  agentSettingsFormSchema,
  newAgentSettingsFormSchema,
} from '../schemas'
import { DirectoryMultiPicker } from './directory-multi-picker'
import {
  type Editing,
  FORM_CLASS,
  FormActions,
  PICKER_PAGE,
  RowsSkeleton,
  Saved,
  Section,
  useDirectory,
} from './directory-shared'
import { DirectoryTable } from './directory-table'
import { RecordLink } from './record-link'

const DEFAULT_LEVEL = '3'
const AVAILABILITY_OPTIONS = AGENT_AVAILABILITIES.map((value) => ({
  value,
  label: copy.settings.availabilityControl[value],
}))

function initialValues(agent: Agent | null): AgentSettingsFormValues {
  return {
    user: agent ? { value: agent.user.id, label: agent.user.name, detail: agent.user.email } : null,
    capacity: String(agent?.capacity ?? 10),
    availability: agent?.availability ?? 'available',
    skills:
      agent?.skills.map((skill) => ({ value: skill.id, label: skill.name, level: String(skill.level) })) ??
      [],
    teams: agent?.teams.map((team) => ({ value: team.id, label: team.name })) ?? [],
  }
}

function toInput(values: AgentSettingsFormValues) {
  return {
    capacity: Number(values.capacity),
    availability: values.availability,
    skills: values.skills.map((skill) => ({ skill_id: skill.value, level: Number(skill.level) })),
    team_ids: values.teams.map((team) => team.value),
  }
}

function AgentForm({
  agent,
  onSaved,
  onCancel,
}: {
  agent: Agent | null
  onSaved: () => void
  onCancel: () => void
}) {
  const { tenantId, client } = useDirectory()
  const server = useServerErrors()
  const [userSearch, setUserSearch] = useState('')
  const [skillSearch, setSkillSearch] = useState('')
  const [teamSearch, setTeamSearch] = useState('')
  const debouncedSkillSearch = useDebouncedValue(skillSearch)
  const debouncedTeamSearch = useDebouncedValue(teamSearch)
  const users = useQuery({
    ...agentQueries.availableUsers(tenantId),
    enabled: agent === null && tenantId !== '',
  })
  const skills = useQuery({
    ...skillQueries.list(tenantId, { ...PICKER_PAGE, search: debouncedSkillSearch }),
    enabled: tenantId !== '',
  })
  const teams = useQuery({
    ...teamQueries.list(tenantId, { ...PICKER_PAGE, search: debouncedTeamSearch }),
    enabled: tenantId !== '',
  })
  const form = useForm({
    defaultValues: initialValues(agent),
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: agent ? agentSettingsFormSchema : newAgentSettingsFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        await (agent
          ? updateAgent(agent.id, toInput(value))
          : createAgent({ ...toInput(value), user_id: value.user?.value ?? '' }))
        await client.invalidateQueries({ queryKey: queryKeys.agents.all(tenantId) })
        onSaved()
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={agent ? copy.settings.editNamed.replace('{name}', agent.user.name) : copy.settings.addAgent}
      className={FORM_CLASS}
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={copy.settings.failed} error={server.failure} /> : null}
      <FieldGroup>
        {agent ? (
          <p className="text-sm">{fill(copy.settings.userNamed, { name: agent.user.name })}</p>
        ) : (
          <form.Field name="user">
            {(field) => (
              <EntityCombobox
                id="agent-user"
                label={copy.settings.user}
                value={field.state.value}
                onChange={field.handleChange}
                // The endpoint returns every user without an Agent profile; it has no search parameter.
                options={
                  users.data
                    ?.filter((user) =>
                      `${user.name} ${user.email}`.toLowerCase().includes(userSearch.toLowerCase()),
                    )
                    .map((user) => ({ value: user.id, label: user.name, detail: user.email })) ?? []
                }
                onQueryChange={setUserSearch}
                isLoading={users.isFetching}
                errors={mergeMessages(field.state.meta.errors, server.fields.user_id)}
              />
            )}
          </form.Field>
        )}
        <form.Field name="capacity">
          {(field) => (
            <TextField
              id="agent-capacity"
              label={copy.settings.capacity}
              type="number"
              inputMode="numeric"
              min={1}
              max={100}
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.capacity)}
            />
          )}
        </form.Field>
        <form.Field name="availability">
          {(field) => (
            <SelectField
              id="agent-availability"
              label={copy.settings.availability}
              value={field.state.value}
              onValueChange={field.handleChange}
              options={AVAILABILITY_OPTIONS}
              errors={mergeMessages(field.state.meta.errors, server.fields.availability)}
            />
          )}
        </form.Field>
        <form.Field name="skills" mode="array">
          {(field) => (
            <>
              <DirectoryMultiPicker
                id="agent-skills"
                label={copy.settings.skills}
                selected={field.state.value}
                options={skills.data?.data.map((skill) => ({ value: skill.id, label: skill.name })) ?? []}
                onQueryChange={setSkillSearch}
                isLoading={skills.isFetching}
                onChange={(next) =>
                  field.handleChange(
                    next.map((skill) => ({
                      ...skill,
                      level:
                        field.state.value.find((row) => row.value === skill.value)?.level ?? DEFAULT_LEVEL,
                    })),
                  )
                }
                errors={mergeMessages(field.state.meta.errors, server.exact.skills)}
              />
              {field.state.value.map((skill, index) => (
                <form.Field key={skill.value} name={`skills[${index}].level`}>
                  {(level) => (
                    <TextField
                      id={`agent-skill-level-${skill.value}`}
                      label={fill(copy.settings.skillLevelNamed, { skill: skill.label })}
                      type="number"
                      inputMode="numeric"
                      min={1}
                      max={5}
                      value={level.state.value}
                      onValueChange={level.handleChange}
                      onBlur={level.handleBlur}
                      errors={mergeMessages(level.state.meta.errors, [
                        ...(server.exact[`skills.${index}.level`] ?? []),
                        ...(server.exact[`skills.${index}.skill_id`] ?? []),
                      ])}
                    />
                  )}
                </form.Field>
              ))}
            </>
          )}
        </form.Field>
        <form.Field name="teams">
          {(field) => (
            <DirectoryMultiPicker
              id="agent-teams"
              label={copy.settings.teams}
              selected={field.state.value}
              options={teams.data?.data.map((team) => ({ value: team.id, label: team.name })) ?? []}
              onQueryChange={setTeamSearch}
              onChange={field.handleChange}
              isLoading={teams.isFetching}
              errors={mergeMessages(field.state.meta.errors, server.fields.team_ids)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <FormActions isSubmitting={isSubmitting} saveLabel={copy.settings.saveAgent} onCancel={onCancel} />
        )}
      </form.Subscribe>
    </form>
  )
}

export function AgentSettings() {
  const allowed = useCan('agents.view')
  const canManage = useCan('agents.manage')
  const { tenantId } = useDirectory()
  const list = useListParams(agentListSchema)
  const agents = useQuery({
    ...agentQueries.list(tenantId, list.apiQuery),
    enabled: allowed && tenantId !== '',
  })
  const [editing, setEditing] = useState<Editing<Agent>>(undefined)
  const [saved, setSaved] = useState(false)
  if (!allowed) return <ForbiddenState />
  return (
    <Section title={copy.settings.agents} description={copy.settings.descriptions.agents}>
      {canManage ? (
        <Button
          onClick={() => {
            setSaved(false)
            setEditing(null)
          }}
        >
          {copy.settings.addAgent}
        </Button>
      ) : null}
      <Saved show={saved && editing === undefined} />
      {editing !== undefined ? (
        <AgentForm
          key={editing?.id ?? 'new'}
          agent={editing}
          onCancel={() => setEditing(undefined)}
          onSaved={() => {
            setSaved(true)
            setEditing(undefined)
          }}
        />
      ) : null}
      {agents.isPending ? (
        <RowsSkeleton />
      ) : agents.isError ? (
        <ErrorState error={agents.error} onRetry={() => void agents.refetch()} />
      ) : (
        <DirectoryTable
          id="settings-agents"
          label={copy.settings.agents}
          rows={agents.data.data.map((agent) => ({
            id: agent.id,
            name: agent.user.name,
            details: fill(copy.settings.agentDetails, {
              availability: copy.settings.availabilityControl[agent.availability],
              open: agent.active_ticket_count,
              capacity: agent.capacity,
              skills: agent.skills.map((skill) => skill.name).join(', ') || copy.settings.none,
            }),
          }))}
          total={agents.data.meta.total}
          list={list}
          defaultSort={agentListSchema.defaultSort}
          sortableName={false}
          renderName={(row) => <RecordLink kind="agents" id={row.id} name={row.name} />}
          onEdit={
            canManage
              ? (id) => {
                  const agent = agents.data.data.find((row) => row.id === id)
                  if (!agent) return
                  setSaved(false)
                  setEditing(agent)
                }
              : undefined
          }
        />
      )}
    </Section>
  )
}
