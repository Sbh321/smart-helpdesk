import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { PlusIcon } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { EntityCombobox } from '@/components/shared/entity-combobox'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SelectField } from '@/components/shared/select-field'
import { TagInput } from '@/components/shared/tag-input'
import { TextField } from '@/components/shared/text-field'
import { TextareaField } from '@/components/shared/textarea-field'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { FieldGroup } from '@/components/ui/field'
import { copy, fill } from '@/copy/en'
import {
  type Contact,
  ContactForm,
  contactQueries,
  useContactOptions,
  useTagSuggestions,
} from '@/features/contacts'
import { AttachmentsField, type AttachmentUploaderState } from '@/features/media'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { mergeMessages, serverFieldErrors } from '@/lib/forms/messages'
import { categoryQueries, createTicket, type Ticket } from '../api/ticket-queries'
import { type TicketFormValues, ticketFormSchema } from '../schemas'
import { DuplicatePreviewPanel } from './duplicate-preview-panel'

const LEVELS = [1, 2, 3, 4] as const
const IMPACT_OPTIONS = LEVELS.map((level) => ({ value: level, label: copy.tickets.impact[level] }))
const URGENCY_OPTIONS = LEVELS.map((level) => ({ value: level, label: copy.tickets.urgency[level] }))

const EMPTY: TicketFormValues = {
  title: '',
  description: '',
  contact: null,
  category_id: null,
  impact: null,
  urgency: null,
  tags: [],
}

/**
 * "New ticket" (roadmap M1-17): the minimal create form in a dialog. `POST /v1/tickets` answers 201 with
 * the numbered ticket; its 422 messages land on the fields (`contact_id` on the contact picker).
 */
export function CreateTicketDialog({
  page = false,
  onCreated,
}: {
  page?: boolean
  onCreated?: (ticket: Ticket) => void
} = {}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(page)
  const [creatingContact, setCreatingContact] = useState(false)
  const [selectedContact, setSelectedContact] = useState<string | null>(null)
  const canCreateContact = useCan('contacts.manage')
  const canReadContact = useCan('contacts.view')
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})
  const [failure, setFailure] = useState<unknown>(null)
  const [attachments, setAttachments] = useState<AttachmentUploaderState>({ mediaIds: [], uploading: false })
  // A new key remounts the field, which aborts running uploads and drops their late results.
  const [uploadCycle, setUploadCycle] = useState(0)
  const contacts = useContactOptions()
  const contact = useQuery({
    ...contactQueries.detail(tenantId, selectedContact ?? ''),
    enabled: canReadContact && selectedContact !== null,
  })
  const tags = useTagSuggestions()
  const categories = useQuery({ ...categoryQueries.all(tenantId), enabled: open && tenantId !== '' })
  const categoryOptions =
    categories.data
      ?.filter((category) => category.is_active)
      .map((category) => ({ value: category.id, label: category.name })) ?? []

  const form = useForm({
    defaultValues: EMPTY,
    // Validate on submit; after a failed submit, re-check as the user corrects the fields.
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: ticketFormSchema },
    onSubmit: async ({ value }) => {
      setServerErrors({})
      setFailure(null)
      try {
        const ticket = await createTicket({
          title: value.title.trim(),
          description: value.description.trim(),
          contact_id: value.contact?.value ?? '',
          category_id: value.category_id ?? '',
          impact: value.impact ?? 0,
          urgency: value.urgency ?? 0,
          ...(value.tags.length > 0 ? { tags: value.tags } : {}),
          ...(attachments.mediaIds.length > 0 ? { attachment_ids: attachments.mediaIds } : {}),
        })
        await queryClient.invalidateQueries({ queryKey: queryKeys.tickets.all(tenantId) })
        toast.success(fill(copy.tickets.create.created, { number: ticket.number }))
        setOpen(false)
        form.reset()
        setAttachments({ mediaIds: [], uploading: false })
        setUploadCycle((current) => current + 1)
        onCreated?.(ticket)
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

  function contactCreated(saved: Contact) {
    form.setFieldValue('contact', { value: saved.id, label: saved.name, detail: saved.email })
    setSelectedContact(saved.id)
    setCreatingContact(false)
  }

  const content = (
    <>
      <form
        noValidate
        id="create-ticket"
        aria-label={copy.tickets.create.title}
        onSubmit={(event) => {
          event.preventDefault()
          void form.handleSubmit()
        }}
        className="flex flex-col gap-5"
      >
        {failure ? <FormErrorBanner title={copy.tickets.create.failed} error={failure} /> : null}
        <FieldGroup>
          <form.Field name="title">
            {(field) => (
              <TextField
                id="ticket-title"
                label={copy.tickets.create.titleLabel}
                autoComplete="off"
                value={field.state.value}
                onValueChange={field.handleChange}
                onBlur={field.handleBlur}
                errors={mergeMessages(field.state.meta.errors, serverErrors.title)}
              />
            )}
          </form.Field>
          <form.Field name="description">
            {(field) => (
              <TextareaField
                id="ticket-description"
                label={copy.tickets.create.descriptionLabel}
                rows={4}
                value={field.state.value}
                onValueChange={field.handleChange}
                onBlur={field.handleBlur}
                errors={mergeMessages(field.state.meta.errors, serverErrors.description)}
              />
            )}
          </form.Field>
          <form.Field name="contact">
            {(field) => (
              <EntityCombobox
                id="ticket-contact"
                label={copy.tickets.create.contactLabel}
                placeholder={copy.tickets.create.contactPlaceholder}
                value={field.state.value}
                onChange={(value) => {
                  field.handleChange(value)
                  setSelectedContact(value?.value ?? null)
                }}
                options={contacts.options}
                onQueryChange={contacts.setQuery}
                isLoading={contacts.isLoading}
                errors={mergeMessages(field.state.meta.errors, serverErrors.contact_id)}
              />
            )}
          </form.Field>
          {canCreateContact ? (
            <Button type="button" variant="outline" onClick={() => setCreatingContact(true)}>
              {copy.tickets.create.newContact}
            </Button>
          ) : null}
          {selectedContact && canReadContact ? (
            <p className="text-sm text-muted-foreground" aria-live="polite">
              {contact.isPending
                ? copy.tickets.create.loadingContact
                : contact.isError
                  ? copy.tickets.create.contactUnavailable
                  : (contact.data?.organization?.name ?? copy.contacts.list.noOrganizationOption)}
            </p>
          ) : null}
          <form.Field name="category_id">
            {(field) => (
              <SelectField
                id="ticket-category"
                label={copy.tickets.create.categoryLabel}
                placeholder={copy.tickets.create.categoryPlaceholder}
                value={field.state.value}
                onValueChange={field.handleChange}
                options={categoryOptions}
                errors={mergeMessages(field.state.meta.errors, serverErrors.category_id)}
              />
            )}
          </form.Field>
          <div className="grid gap-5 sm:grid-cols-2">
            <form.Field name="impact">
              {(field) => (
                <SelectField
                  id="ticket-impact"
                  label={copy.tickets.create.impactLabel}
                  description={copy.tickets.create.impactDescription}
                  value={field.state.value}
                  onValueChange={field.handleChange}
                  options={IMPACT_OPTIONS}
                  errors={mergeMessages(field.state.meta.errors, serverErrors.impact)}
                />
              )}
            </form.Field>
            <form.Field name="urgency">
              {(field) => (
                <SelectField
                  id="ticket-urgency"
                  label={copy.tickets.create.urgencyLabel}
                  description={copy.tickets.create.urgencyDescription}
                  value={field.state.value}
                  onValueChange={field.handleChange}
                  options={URGENCY_OPTIONS}
                  errors={mergeMessages(field.state.meta.errors, serverErrors.urgency)}
                />
              )}
            </form.Field>
          </div>
          <form.Field name="tags">
            {(field) => (
              <TagInput
                id="ticket-tags"
                label={copy.tickets.create.tagsLabel}
                value={field.state.value}
                onChange={field.handleChange}
                suggestions={tags.suggestions}
                onQueryChange={tags.setQuery}
                errors={mergeMessages(field.state.meta.errors, serverErrors.tags)}
              />
            )}
          </form.Field>
        </FieldGroup>
        <div className="grid gap-3 sm:grid-cols-2">
          <section className="rounded-lg border border-dashed border-border p-4">
            <h2 className="text-sm font-medium">{copy.tickets.detail.attachments}</h2>
            <AttachmentsField key={uploadCycle} onChange={setAttachments} />
          </section>
          <form.Subscribe selector={(state) => [state.values.title, state.values.description] as const}>
            {([title, description]) => (
              <DuplicatePreviewPanel title={title} description={description} active={open} />
            )}
          </form.Subscribe>
        </div>
      </form>
      <div className="mt-5 flex justify-end gap-2">
        {page ? null : (
          <DialogClose render={<Button type="button" variant="outline" />}>
            {copy.tickets.create.cancel}
          </DialogClose>
        )}
        <form.Subscribe selector={(state) => state.isSubmitting}>
          {(isSubmitting) => (
            <Button type="submit" form="create-ticket" disabled={isSubmitting || attachments.uploading}>
              {isSubmitting ? copy.tickets.create.submitting : copy.tickets.create.submit}
            </Button>
          )}
        </form.Subscribe>
      </div>
      <Dialog open={creatingContact} onOpenChange={setCreatingContact}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{copy.contacts.newTitle}</DialogTitle>
            <DialogDescription>{copy.contacts.description}</DialogDescription>
          </DialogHeader>
          <ContactForm onSaved={contactCreated} />
        </DialogContent>
      </Dialog>
    </>
  )

  if (page) return <div className="max-w-3xl">{content}</div>

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (!next) {
          form.reset()
          setSelectedContact(null)
          setServerErrors({})
          setFailure(null)
          setAttachments({ mediaIds: [], uploading: false })
          setUploadCycle((current) => current + 1)
        }
      }}
    >
      <DialogTrigger render={<Button type="button" />}>
        <PlusIcon aria-hidden="true" />
        {copy.tickets.create.open}
      </DialogTrigger>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{copy.tickets.create.title}</DialogTitle>
          <DialogDescription>{copy.tickets.create.description}</DialogDescription>
        </DialogHeader>
        {content}
      </DialogContent>
    </Dialog>
  )
}
