import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { EntityCombobox } from '@/components/shared/entity-combobox'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { useDebouncedValue } from '@/lib/use-debounced-value'
import {
  type Category,
  type CategoryInput,
  categoryQueries,
  createCategory,
  skillQueries,
  teamQueries,
  updateCategory,
} from '../api/agent-queries'
import { type CategoryFormValues, categoryFormSchema } from '../schemas'
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

function initialValues(category: Category | null): CategoryFormValues {
  return {
    name: category?.name ?? '',
    team: category?.default_team
      ? { value: category.default_team.id, label: category.default_team.name }
      : null,
    skills: category?.required_skills.map((skill) => ({ value: skill.id, label: skill.name })) ?? [],
  }
}

function toInput(values: CategoryFormValues, category: Category | null): CategoryInput {
  return {
    name: values.name.trim(),
    default_team_id: values.team?.value ?? null,
    skill_ids: values.skills.map((skill) => skill.value),
    is_active: true,
    sort_order: category?.sort_order ?? 0,
  }
}

function CategoryForm({
  category,
  onSaved,
  onCancel,
}: {
  category: Category | null
  onSaved: () => void
  onCancel: () => void
}) {
  const { tenantId, client } = useDirectory()
  const server = useServerErrors()
  const [teamSearch, setTeamSearch] = useState('')
  const [skillSearch, setSkillSearch] = useState('')
  const debouncedTeamSearch = useDebouncedValue(teamSearch)
  const debouncedSkillSearch = useDebouncedValue(skillSearch)
  const teams = useQuery({
    ...teamQueries.list(tenantId, { ...PICKER_PAGE, search: debouncedTeamSearch }),
    enabled: tenantId !== '',
  })
  const skills = useQuery({
    ...skillQueries.list(tenantId, { ...PICKER_PAGE, search: debouncedSkillSearch }),
    enabled: tenantId !== '',
  })
  const form = useForm({
    defaultValues: initialValues(category),
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: categoryFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        const input = toInput(value, category)
        await (category ? updateCategory(category.id, input) : createCategory(input))
        await client.invalidateQueries({ queryKey: queryKeys.categories.all(tenantId) })
        onSaved()
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={
        category ? copy.settings.editNamed.replace('{name}', category.name) : copy.settings.addCategory
      }
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
              id="category-name"
              label={copy.settings.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="team">
          {(field) => (
            <EntityCombobox
              id="category-default-team"
              label={copy.settings.defaultTeam}
              value={field.state.value}
              onChange={field.handleChange}
              options={teams.data?.data.map((team) => ({ value: team.id, label: team.name })) ?? []}
              onQueryChange={setTeamSearch}
              isLoading={teams.isFetching}
              errors={mergeMessages(field.state.meta.errors, server.fields.default_team_id)}
            />
          )}
        </form.Field>
        <form.Field name="skills">
          {(field) => (
            <DirectoryMultiPicker
              id="category-skills"
              label={copy.settings.requiredSkills}
              selected={field.state.value}
              onChange={field.handleChange}
              onQueryChange={setSkillSearch}
              options={skills.data?.data.map((skill) => ({ value: skill.id, label: skill.name })) ?? []}
              isLoading={skills.isFetching}
              errors={mergeMessages(field.state.meta.errors, server.fields.skill_ids)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <FormActions
            isSubmitting={isSubmitting}
            saveLabel={copy.settings.saveCategory}
            onCancel={onCancel}
          />
        )}
      </form.Subscribe>
    </form>
  )
}

export function CategorySettings() {
  const allowed = useCan('tickets.view')
  const canManage = useCan('settings.manage')
  const { tenantId } = useDirectory()
  const categories = useQuery({ ...categoryQueries.all(tenantId), enabled: allowed && tenantId !== '' })
  const [editing, setEditing] = useState<Editing<Category>>(undefined)
  const [saved, setSaved] = useState(false)
  if (!allowed) return <ForbiddenState />
  return (
    <Section title={copy.settings.categories}>
      {canManage ? (
        <Button
          onClick={() => {
            setSaved(false)
            setEditing(null)
          }}
        >
          {copy.settings.addCategory}
        </Button>
      ) : null}
      <Saved show={saved && editing === undefined} />
      {editing !== undefined ? (
        <CategoryForm
          key={editing?.id ?? 'new'}
          category={editing}
          onCancel={() => setEditing(undefined)}
          onSaved={() => {
            setSaved(true)
            setEditing(undefined)
          }}
        />
      ) : null}
      {categories.isPending ? (
        <p aria-busy="true">{copy.settings.loading}</p>
      ) : categories.isError ? (
        <ErrorState error={categories.error} onRetry={() => void categories.refetch()} />
      ) : categories.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">{copy.settings.empty}</p>
      ) : (
        <Table>
          <caption className="sr-only">{copy.settings.categories}</caption>
          <TableHeader>
            <TableRow>
              <TableHead>{copy.settings.name}</TableHead>
              <TableHead>{copy.settings.defaultTeam}</TableHead>
              <TableHead>{copy.settings.requiredSkills}</TableHead>
              {canManage ? <TableHead>{copy.settings.actions}</TableHead> : null}
            </TableRow>
          </TableHeader>
          <TableBody>
            {categories.data.map((category) => (
              <TableRow key={category.id}>
                <TableCell>{category.name}</TableCell>
                <TableCell>{category.default_team?.name ?? copy.settings.none}</TableCell>
                <TableCell>
                  {category.required_skills.map((skill) => skill.name).join(', ') || copy.settings.none}
                </TableCell>
                {canManage ? (
                  <TableCell>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setSaved(false)
                        setEditing(category)
                      }}
                    >
                      {copy.settings.editNamed.replace('{name}', category.name)}
                    </Button>
                  </TableCell>
                ) : null}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Section>
  )
}
