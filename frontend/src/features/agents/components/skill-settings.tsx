import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
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
import {
  createSkill,
  directoryListSchema,
  type Skill,
  type SkillInput,
  skillQueries,
  updateSkill,
} from '../api/agent-queries'
import { type SkillFormValues, skillFormSchema } from '../schemas'
import { type Editing, FORM_CLASS, FormActions, Saved, Section, useDirectory } from './directory-shared'
import { DirectoryTable } from './directory-table'

export function slugify(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

function toInput(values: SkillFormValues): SkillInput {
  return { name: values.name.trim(), slug: values.slug.trim(), description: values.description.trim() }
}

function SkillForm({
  skill,
  onSaved,
  onCancel,
}: {
  skill: Skill | null
  onSaved: () => void
  onCancel: () => void
}) {
  const { tenantId, client } = useDirectory()
  const server = useServerErrors()
  const form = useForm({
    defaultValues: {
      name: skill?.name ?? '',
      slug: skill?.slug ?? '',
      description: skill?.description ?? '',
    } satisfies SkillFormValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: skillFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        await (skill ? updateSkill(skill.id, toInput(value)) : createSkill(toInput(value)))
        await client.invalidateQueries({ queryKey: queryKeys.skills.all(tenantId) })
        onSaved()
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={skill ? copy.settings.editNamed.replace('{name}', skill.name) : copy.settings.addSkill}
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
              id="skill-name"
              label={copy.settings.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={(value) => {
                field.handleChange(value)
                // A new Skill's slug follows its name until the slug is edited by hand.
                if (!skill && !form.getFieldMeta('slug')?.isDirty) {
                  form.setFieldValue('slug', slugify(value), { dontUpdateMeta: true })
                }
              }}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="slug">
          {(field) => (
            <TextField
              id="skill-slug"
              label={copy.settings.slug}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.slug)}
            />
          )}
        </form.Field>
        <form.Field name="description">
          {(field) => (
            <TextField
              id="skill-description"
              label={copy.settings.descriptionLabel}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.description)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <FormActions isSubmitting={isSubmitting} saveLabel={copy.settings.saveSkill} onCancel={onCancel} />
        )}
      </form.Subscribe>
    </form>
  )
}

export function SkillSettings() {
  const allowed = useCan('agents.view')
  const canManage = useCan('agents.manage')
  const { tenantId } = useDirectory()
  const list = useListParams(directoryListSchema)
  const result = useQuery({
    ...skillQueries.list(tenantId, list.apiQuery),
    enabled: allowed && tenantId !== '',
  })
  const [editing, setEditing] = useState<Editing<Skill>>(undefined)
  const [saved, setSaved] = useState(false)
  if (!allowed) return <ForbiddenState />
  return (
    <Section title={copy.settings.skills}>
      {canManage ? (
        <Button
          onClick={() => {
            setSaved(false)
            setEditing(null)
          }}
        >
          {copy.settings.addSkill}
        </Button>
      ) : null}
      <Saved show={saved && editing === undefined} />
      {editing !== undefined ? (
        <SkillForm
          key={editing?.id ?? 'new'}
          skill={editing}
          onCancel={() => setEditing(undefined)}
          onSaved={() => {
            setSaved(true)
            setEditing(undefined)
          }}
        />
      ) : null}
      {result.isPending ? (
        <p aria-busy="true">{copy.settings.loading}</p>
      ) : result.isError ? (
        <ErrorState error={result.error} onRetry={() => void result.refetch()} />
      ) : (
        <DirectoryTable
          id="settings-skills"
          label={copy.settings.skills}
          rows={result.data.data.map((skill) => ({ id: skill.id, name: skill.name, details: skill.slug }))}
          total={result.data.meta.total}
          list={list}
          defaultSort={directoryListSchema.defaultSort}
          sortableName
          onEdit={
            canManage
              ? (id) => {
                  const skill = result.data.data.find((row) => row.id === id)
                  if (!skill) return
                  setSaved(false)
                  setEditing(skill)
                }
              : undefined
          }
        />
      )}
    </Section>
  )
}
