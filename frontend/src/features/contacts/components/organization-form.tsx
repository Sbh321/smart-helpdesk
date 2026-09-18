import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SelectField } from '@/components/shared/select-field'
import { TagInput } from '@/components/shared/tag-input'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { mergeMessages, serverFieldErrors } from '@/lib/forms/messages'
import {
  createOrganization,
  ORGANIZATION_TIERS,
  type Organization,
  type OrganizationInput,
  type OrganizationTier,
  updateOrganization,
} from '../api/organization-queries'
import { type OrganizationFormValues, organizationFormSchema } from '../schemas'
import { useTagSuggestions, useTenantId } from './use-pickers'

const TIER_OPTIONS = ORGANIZATION_TIERS.map((tier) => ({ value: tier, label: copy.organizations.tier[tier] }))

function initialValues(organization?: Organization): OrganizationFormValues {
  const tier = (ORGANIZATION_TIERS as readonly string[]).includes(organization?.tier ?? '')
    ? (organization?.tier as OrganizationTier)
    : 'standard'
  return {
    name: organization?.name ?? '',
    domain: organization?.domain ?? '',
    tier,
    tags: organization?.tags.map((tag) => tag.name) ?? [],
  }
}

function toInput(values: OrganizationFormValues): OrganizationInput {
  const domain = values.domain.trim()
  return {
    name: values.name.trim(),
    domain: domain === '' ? null : domain,
    tier: values.tier,
    tags: values.tags,
  }
}

/** Create or edit an organisation (roadmap M1-15). The tier feeds the priority of new tickets. */
export function OrganizationForm({
  organization,
  onSaved,
}: {
  organization?: Organization
  onSaved: (organization: Organization) => void
}) {
  const tenantId = useTenantId()
  const queryClient = useQueryClient()
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})
  const [failure, setFailure] = useState<unknown>(null)
  const tags = useTagSuggestions()
  const prefix = organization ? `organization-${organization.id}` : 'organization-new'

  const form = useForm({
    defaultValues: initialValues(organization),
    // Validate on submit; after a failed submit, re-check as the user corrects the fields.
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: organizationFormSchema },
    onSubmit: async ({ value }) => {
      setServerErrors({})
      setFailure(null)
      try {
        const saved = organization
          ? await updateOrganization(organization.id, toInput(value))
          : await createOrganization(toInput(value))
        await queryClient.invalidateQueries({ queryKey: queryKeys.organizations.all(tenantId) })
        await queryClient.invalidateQueries({ queryKey: queryKeys.tags.all(tenantId) })
        queryClient.setQueryData(queryKeys.organizations.detail(tenantId, saved.id), saved)
        toast.success(
          organization
            ? copy.organizations.form.saved
            : fill(copy.organizations.form.created, { name: saved.name }),
        )
        onSaved(saved)
      } catch (error) {
        const fields = serverFieldErrors(error)
        if (Object.keys(fields).length > 0) {
          setServerErrors(fields)
        } else {
          setFailure(error)
        }
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={organization ? copy.organizations.editTitle : copy.organizations.newTitle}
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
      className="flex max-w-xl flex-col gap-5"
    >
      {failure ? <FormErrorBanner title={copy.organizations.form.failed} error={failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id={`${prefix}-name`}
              label={copy.organizations.form.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.name)}
            />
          )}
        </form.Field>
        <form.Field name="domain">
          {(field) => (
            <TextField
              id={`${prefix}-domain`}
              label={copy.organizations.form.domain}
              description={copy.organizations.form.domainDescription}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.domain)}
            />
          )}
        </form.Field>
        <form.Field name="tier">
          {(field) => (
            <SelectField
              id={`${prefix}-tier`}
              label={copy.organizations.form.tier}
              description={copy.organizations.form.tierDescription}
              value={field.state.value}
              onValueChange={field.handleChange}
              options={TIER_OPTIONS}
              errors={mergeMessages(field.state.meta.errors, serverErrors.tier)}
            />
          )}
        </form.Field>
        <form.Field name="tags">
          {(field) => (
            <TagInput
              id={`${prefix}-tags`}
              label={copy.organizations.form.tags}
              description={copy.contacts.form.tagsDescription}
              value={field.state.value}
              onChange={field.handleChange}
              suggestions={tags.suggestions}
              onQueryChange={tags.setQuery}
              errors={mergeMessages(field.state.meta.errors, serverErrors.tags)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <div>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting
                ? copy.organizations.form.saving
                : organization
                  ? copy.organizations.form.save
                  : copy.organizations.form.create}
            </Button>
          </div>
        )}
      </form.Subscribe>
    </form>
  )
}
