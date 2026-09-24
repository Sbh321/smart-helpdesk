import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SelectField } from '@/components/shared/select-field'
import { TagInput } from '@/components/shared/tag-input'
import { TextField } from '@/components/shared/text-field'
import { TextareaField } from '@/components/shared/textarea-field'
import { Button } from '@/components/ui/button'
import { toast } from '@/components/ui/sonner'
import { copy } from '@/copy/en'
import { useTagSuggestions } from '@/features/contacts'
import { queryKeys } from '@/lib/api/query-keys'
import { useSession } from '@/lib/auth'
import { mergeMessages, serverFieldErrors } from '@/lib/forms/messages'
import { categoryQueries, type Ticket, updateTicket } from '../api/ticket-queries'
import { type TicketFormValues, ticketFormSchema } from '../schemas'

const schema = ticketFormSchema.omit({ contact: true })
const levels = [1, 2, 3, 4] as const

export function EditTicketForm({ ticket, onSaved }: { ticket: Ticket; onSaved: () => void }) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const client = useQueryClient()
  const categories = useQuery(categoryQueries.all(tenantId))
  const tags = useTagSuggestions()
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failure, setFailure] = useState<unknown>(null)
  const form = useForm({
    defaultValues: {
      title: ticket.title,
      description: ticket.description,
      category_id: ticket.category_id,
      impact: ticket.impact,
      urgency: ticket.urgency,
      tags: ticket.tags?.map((tag) => tag.name) ?? [],
    } as Omit<TicketFormValues, 'contact'>,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: schema },
    onSubmit: async ({ value }) => {
      setErrors({})
      setFailure(null)
      try {
        const saved = await updateTicket(ticket.id, {
          ...value,
          category_id: value.category_id ?? '',
          impact: value.impact ?? 0,
          urgency: value.urgency ?? 0,
        })
        client.setQueryData(queryKeys.tickets.detail(tenantId, ticket.id), saved)
        await client.invalidateQueries({ queryKey: queryKeys.tickets.all(tenantId) })
        toast.success(copy.tickets.detail.saved)
        onSaved()
      } catch (error) {
        const fields = serverFieldErrors(error)
        if (Object.keys(fields).length > 0) {
          setErrors(fields)
        } else {
          setFailure(error)
        }
      }
    },
  })
  const options =
    categories.data
      ?.filter((category) => category.is_active || category.id === ticket.category_id)
      .map((category) => ({ value: category.id, label: category.name })) ?? []
  return (
    <form
      noValidate
      aria-label={copy.tickets.detail.edit}
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {failure ? <FormErrorBanner title={copy.tickets.detail.failed} error={failure} /> : null}
      <form.Field name="title">
        {(field) => (
          <TextField
            id="edit-ticket-title"
            label={copy.tickets.create.titleLabel}
            value={field.state.value}
            onValueChange={field.handleChange}
            errors={mergeMessages(field.state.meta.errors, errors.title)}
          />
        )}
      </form.Field>
      <form.Field name="description">
        {(field) => (
          <TextareaField
            id="edit-ticket-description"
            label={copy.tickets.create.descriptionLabel}
            value={field.state.value}
            onValueChange={field.handleChange}
            errors={mergeMessages(field.state.meta.errors, errors.description)}
          />
        )}
      </form.Field>
      <form.Field name="category_id">
        {(field) => (
          <SelectField
            id="edit-ticket-category"
            label={copy.tickets.create.categoryLabel}
            value={field.state.value}
            onValueChange={(value) => field.handleChange(value ?? '')}
            options={options}
            errors={mergeMessages(field.state.meta.errors, errors.category_id)}
          />
        )}
      </form.Field>
      {(['impact', 'urgency'] as const).map((name) => (
        <form.Field key={name} name={name}>
          {(field) => (
            <SelectField
              id={`edit-ticket-${name}`}
              label={copy.tickets.create[`${name}Label`]}
              description={copy.tickets.create[`${name}Description`]}
              value={field.state.value}
              onValueChange={(value) => field.handleChange(value ?? 1)}
              options={levels.map((value) => ({ value, label: copy.tickets[name][value] }))}
              errors={mergeMessages(field.state.meta.errors, errors[name])}
            />
          )}
        </form.Field>
      ))}
      <form.Field name="tags">
        {(field) => (
          <TagInput
            id="edit-ticket-tags"
            label={copy.tickets.create.tagsLabel}
            value={field.state.value}
            onChange={field.handleChange}
            suggestions={tags.suggestions}
            onQueryChange={tags.setQuery}
            errors={mergeMessages(field.state.meta.errors, errors.tags)}
          />
        )}
      </form.Field>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(busy) => (
          <Button type="submit" disabled={busy}>
            {busy ? copy.tickets.detail.saving : copy.tickets.detail.save}
          </Button>
        )}
      </form.Subscribe>
    </form>
  )
}
