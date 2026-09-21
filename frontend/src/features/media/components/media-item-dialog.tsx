import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SelectField } from '@/components/shared/select-field'
import { TagInput } from '@/components/shared/tag-input'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { FieldGroup } from '@/components/ui/field'
import { copy } from '@/copy/en'
import { useTagSuggestions } from '@/features/contacts'
import { queryKeys } from '@/lib/api/query-keys'
import { useSession } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { type MediaFolder, type MediaItem, updateMedia } from '../api/media-queries'
import { type MediaItemFormValues, mediaItemFormSchema, NO_FOLDER } from '../schemas'

function ItemForm({
  item,
  folders,
  onDone,
}: {
  item: MediaItem
  folders: readonly MediaFolder[]
  onDone: () => void
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const client = useQueryClient()
  const server = useServerErrors()
  const tags = useTagSuggestions()
  const defaultValues: MediaItemFormValues = {
    name: item.name,
    folder: item.folder_id ?? NO_FOLDER,
    tags: item.tags.map((tag) => tag.name),
  }
  const form = useForm({
    defaultValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: mediaItemFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        await updateMedia(item.id, {
          name: value.name.trim(),
          folder_id: value.folder === NO_FOLDER ? null : value.folder,
          tags: value.tags,
        })
        await client.invalidateQueries({ queryKey: queryKeys.media.all(tenantId) })
        await client.invalidateQueries({ queryKey: queryKeys.tags.all(tenantId) })
        toast.success(copy.media.saved)
        onDone()
      } catch (error) {
        server.capture(error)
      }
    },
  })
  const folderOptions = [
    { value: NO_FOLDER, label: copy.media.noFolder },
    ...folders.map((folder) => ({ value: folder.id, label: folder.name })),
  ]

  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        event.stopPropagation()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={copy.media.failed} error={server.failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id="media-item-name"
              label={copy.media.columns.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="folder">
          {(field) => (
            <SelectField
              id="media-item-folder"
              label={copy.media.move}
              value={field.state.value}
              onValueChange={field.handleChange}
              options={folderOptions}
              errors={mergeMessages(field.state.meta.errors, server.fields.folder_id)}
            />
          )}
        </form.Field>
        <form.Field name="tags">
          {(field) => (
            <TagInput
              id="media-item-tags"
              label={copy.media.tags}
              value={field.state.value}
              onChange={field.handleChange}
              suggestions={tags.suggestions}
              onQueryChange={tags.setQuery}
              errors={mergeMessages(field.state.meta.errors, server.fields.tags)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <DialogFooter>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={onDone}>
              {copy.media.cancel}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? copy.media.saving : copy.media.save}
            </Button>
          </DialogFooter>
        )}
      </form.Subscribe>
    </form>
  )
}

export function MediaItemDialog({
  item,
  folders,
  onClose,
}: {
  item: MediaItem | null
  folders: readonly MediaFolder[]
  onClose: () => void
}) {
  return (
    <Dialog
      open={item !== null}
      onOpenChange={(open) => {
        if (!open) onClose()
      }}
    >
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{copy.media.editTitle}</DialogTitle>
          <DialogDescription>{copy.media.editDescription}</DialogDescription>
        </DialogHeader>
        {item ? <ItemForm key={item.id} item={item} folders={folders} onDone={onClose} /> : null}
      </DialogContent>
    </Dialog>
  )
}
