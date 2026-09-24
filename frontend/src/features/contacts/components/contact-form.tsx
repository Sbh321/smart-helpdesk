import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { EntityCombobox } from '@/components/shared/entity-combobox'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { TagInput } from '@/components/shared/tag-input'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { mergeMessages, serverFieldErrors } from '@/lib/forms/messages'
import { type Contact, type ContactInput, createContact, updateContact } from '../api/contact-queries'
import { type ContactFormValues, contactFormSchema } from '../schemas'
import { useOrganizationOptions, useTagSuggestions, useTenantId } from './use-pickers'

export interface ContactFormProps {
  /** Absent: create. Present: edit this contact. */
  contact?: Contact
  onSaved: (contact: Contact) => void
}

function initialValues(contact?: Contact): ContactFormValues {
  return {
    name: contact?.name ?? '',
    email: contact?.email ?? '',
    phone: contact?.phone ?? '',
    organization: contact?.organization
      ? { value: contact.organization.id, label: contact.organization.name }
      : null,
    tags: contact?.tags.map((tag) => tag.name) ?? [],
  }
}

function toInput(values: ContactFormValues): ContactInput {
  return {
    name: values.name.trim(),
    email: values.email.trim(),
    phone: values.phone.trim() === '' ? null : values.phone.trim(),
    organization_id: values.organization?.value ?? null,
    tags: values.tags,
  }
}

/**
 * Create or edit a contact (roadmap M1-15). Client rules come from `contactFormSchema`; the API stays the
 * authority, and its 422 messages (a taken email is `errors.email`) land on the same fields.
 */
export function ContactForm({ contact, onSaved }: ContactFormProps) {
  const tenantId = useTenantId()
  const queryClient = useQueryClient()
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})
  const [failure, setFailure] = useState<unknown>(null)
  const organizations = useOrganizationOptions()
  const tags = useTagSuggestions()
  const prefix = contact ? `contact-${contact.id}` : 'contact-new'

  const form = useForm({
    defaultValues: initialValues(contact),
    // Validate on submit; after a failed submit, re-check as the user corrects the fields.
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: contactFormSchema },
    onSubmit: async ({ value }) => {
      setServerErrors({})
      setFailure(null)
      try {
        const saved = contact
          ? await updateContact(contact.id, toInput(value))
          : await createContact(toInput(value))
        await queryClient.invalidateQueries({ queryKey: queryKeys.contacts.all(tenantId) })
        await queryClient.invalidateQueries({ queryKey: queryKeys.tags.all(tenantId) })
        queryClient.setQueryData(queryKeys.contacts.detail(tenantId, saved.id), saved)
        toast.success(
          contact ? copy.contacts.form.saved : fill(copy.contacts.form.created, { name: saved.name }),
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
      aria-label={contact ? copy.contacts.editTitle : copy.contacts.newTitle}
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
      className="flex max-w-xl flex-col gap-5"
    >
      {failure ? <FormErrorBanner title={copy.contacts.form.failed} error={failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id={`${prefix}-name`}
              label={copy.contacts.form.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.name)}
            />
          )}
        </form.Field>
        <form.Field name="email">
          {(field) => (
            <TextField
              id={`${prefix}-email`}
              label={copy.contacts.form.email}
              type="email"
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.email)}
            />
          )}
        </form.Field>
        <form.Field name="phone">
          {(field) => (
            <TextField
              id={`${prefix}-phone`}
              label={copy.contacts.form.phone}
              description={copy.contacts.form.phoneDescription}
              type="tel"
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.phone)}
            />
          )}
        </form.Field>
        <form.Field name="organization">
          {(field) => (
            <EntityCombobox
              id={`${prefix}-organization`}
              label={copy.contacts.form.organization}
              placeholder={copy.contacts.form.organizationPlaceholder}
              value={field.state.value}
              onChange={field.handleChange}
              options={organizations.options}
              onQueryChange={organizations.setQuery}
              isLoading={organizations.isLoading}
              errors={mergeMessages(field.state.meta.errors, serverErrors.organization_id)}
            />
          )}
        </form.Field>
        <form.Field name="tags">
          {(field) => (
            <TagInput
              id={`${prefix}-tags`}
              label={copy.contacts.form.tags}
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
                ? copy.contacts.form.saving
                : contact
                  ? copy.contacts.form.save
                  : copy.contacts.form.create}
            </Button>
          </div>
        )}
      </form.Subscribe>
    </form>
  )
}
